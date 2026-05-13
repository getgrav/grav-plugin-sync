<?php

declare(strict_types=1);

/**
 * Storage backend microbenchmark — FileSyncStorage vs SqliteSyncStorage.
 *
 * Runs a fixed set of scenarios against both backends in a private temp dir
 * and prints a side-by-side ops/sec table. The point is *relative* numbers:
 * absolute throughput will vary wildly by disk/SAPI/OS and is not portable.
 *
 * Usage:
 *   php tests/bench/storage_bench.php           # full suite (default sizes)
 *   php tests/bench/storage_bench.php --quick   # smaller iteration counts
 *   php tests/bench/storage_bench.php --json    # machine-readable JSON
 *   php tests/bench/storage_bench.php --runs=5  # median of N runs (default 3)
 *
 * Caveats:
 *   - Disk-cache state matters. We do a warm-up pass and run the same
 *     scenarios under both backends back-to-back so cache effects bias both
 *     equally rather than one of them.
 *   - Concurrent scenarios require pcntl. They are skipped otherwise.
 *   - Median of N runs (default 3) is reported to dampen single-run noise.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Grav\Plugin\Sync\Storage\FileSyncStorage;
use Grav\Plugin\Sync\Storage\SqliteSyncStorage;
use Grav\Plugin\Sync\SyncStorage;

// ────────────────────────────────────────────────────────────────────────────
// CLI parsing
// ────────────────────────────────────────────────────────────────────────────

$opts = [
    'quick' => false,
    'json'  => false,
    'runs'  => 3,
];
foreach ($argv as $arg) {
    if ($arg === '--quick') {
        $opts['quick'] = true;
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    } elseif (str_starts_with($arg, '--runs=')) {
        $opts['runs'] = max(1, (int)substr($arg, 7));
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — cannot benchmark SQLite backend.\n");
    exit(1);
}
$canFork = function_exists('pcntl_fork');

// ────────────────────────────────────────────────────────────────────────────
// Scenario sizing (toned down for --quick)
// ────────────────────────────────────────────────────────────────────────────

$size = $opts['quick'] ? [
    'seq_appends'        => 500,
    'pull_iters'         => 30,
    'incr_appends'       => 300,
    'incr_pull_every'    => 5,
    'concurrent_workers' => 4,
    'concurrent_per'     => 50,
    'reader_count'       => 2,
    'reader_writer_ops'  => 200,
    'snapshot_iters'     => 50,
    'snapshot_bytes'     => 32 * 1024,
    'update_bytes'       => 200,
] : [
    'seq_appends'        => 2000,
    'pull_iters'         => 100,
    'incr_appends'       => 1000,
    'incr_pull_every'    => 5,
    'concurrent_workers' => 8,
    'concurrent_per'     => 200,
    'reader_count'       => 4,
    'reader_writer_ops'  => 800,
    'snapshot_iters'     => 200,
    'snapshot_bytes'     => 64 * 1024,
    'update_bytes'       => 200,
];

// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

/**
 * @return array{file: float, sqlite: float}  durations in seconds
 */
function timeBoth(callable $fn, string $rootBase): array
{
    $fileRoot = $rootBase . '/file';
    $sqliteRoot = $rootBase . '/sqlite';
    mkdir($fileRoot, 0755, true);
    mkdir($sqliteRoot, 0755, true);

    $file = new FileSyncStorage($fileRoot);
    $sqlite = new SqliteSyncStorage($sqliteRoot);

    $fileStart = hrtime(true);
    $fn($file);
    $fileEnd = hrtime(true);

    $sqliteStart = hrtime(true);
    $fn($sqlite);
    $sqliteEnd = hrtime(true);

    return [
        'file' => ($fileEnd - $fileStart) / 1e9,
        'sqlite' => ($sqliteEnd - $sqliteStart) / 1e9,
    ];
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    if ($n % 2 === 1) {
        return $values[intdiv($n, 2)];
    }
    return ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function rrm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($rii as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            @unlink($f->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Run $fn under both backends, $runs times, return median seconds for each.
 *
 * @return array{file: float, sqlite: float}
 */
function bench(int $runs, callable $fn): array
{
    $base = sys_get_temp_dir() . '/sync-bench-' . bin2hex(random_bytes(4));
    $fileTimes = [];
    $sqliteTimes = [];
    try {
        // Warm-up (not timed).
        $warm = $base . '/warmup';
        mkdir($warm, 0755, true);
        $t = timeBoth($fn, $warm);
        // discard $t

        for ($i = 0; $i < $runs; $i++) {
            $iter = $base . '/run' . $i;
            mkdir($iter, 0755, true);
            $t = timeBoth($fn, $iter);
            $fileTimes[] = $t['file'];
            $sqliteTimes[] = $t['sqlite'];
        }
    } finally {
        rrm($base);
    }
    return ['file' => median($fileTimes), 'sqlite' => median($sqliteTimes)];
}

function payload(int $bytes): string
{
    // Mix of bytes — not all-zero — to be slightly closer to real Yjs blobs.
    return random_bytes($bytes);
}

function fmt(float $opsPerSec): string
{
    if ($opsPerSec >= 100000) {
        return number_format($opsPerSec / 1000, 0) . 'k';
    }
    if ($opsPerSec >= 10000) {
        return number_format($opsPerSec / 1000, 1) . 'k';
    }
    if ($opsPerSec >= 1000) {
        return number_format($opsPerSec, 0);
    }
    return number_format($opsPerSec, 1);
}

function ratio(float $sqliteOps, float $fileOps): string
{
    if ($fileOps <= 0) {
        return '—';
    }
    $r = $sqliteOps / $fileOps;
    return number_format($r, 2) . 'x';
}

// ────────────────────────────────────────────────────────────────────────────
// Scenarios
// ────────────────────────────────────────────────────────────────────────────

$scenarios = [];

// 1. Sequential append, single writer, single room.
$scenarios['seq_append'] = [
    'name' => 'Sequential append, single writer',
    'ops' => $size['seq_appends'],
    'concurrent' => false,
    'run' => function (SyncStorage $s) use ($size): void {
        $room = 'bench/seq@default';
        $blob = payload($size['update_bytes']);
        for ($i = 0; $i < $size['seq_appends']; $i++) {
            $s->appendUpdate($room, $blob, 'c1');
        }
    },
];

// 2. Sequential pull-all (read full log from offset 0).
$scenarios['seq_pull'] = [
    'name' => 'Pull-all (full log scan)',
    'ops' => $size['pull_iters'],
    'concurrent' => false,
    'setup' => function (SyncStorage $s) use ($size): void {
        $room = 'bench/pull@default';
        $blob = payload($size['update_bytes']);
        for ($i = 0; $i < $size['seq_appends']; $i++) {
            $s->appendUpdate($room, $blob, 'c1');
        }
    },
    'run' => function (SyncStorage $s) use ($size): void {
        $room = 'bench/pull@default';
        for ($i = 0; $i < $size['pull_iters']; $i++) {
            $s->getUpdatesSince($room, 0);
        }
    },
];

// 3. Incremental pull (polling-shaped: a few appends then a pull-since).
$scenarios['incremental'] = [
    'name' => 'Incremental pull (polling-shaped)',
    'ops' => $size['incr_appends'],
    'concurrent' => false,
    'run' => function (SyncStorage $s) use ($size): void {
        $room = 'bench/incr@default';
        $blob = payload($size['update_bytes']);
        $cursor = 0;
        for ($i = 0; $i < $size['incr_appends']; $i++) {
            $s->appendUpdate($room, $blob, 'c1');
            if (($i + 1) % $size['incr_pull_every'] === 0) {
                $res = $s->getUpdatesSince($room, $cursor);
                $cursor = $res['offset'];
            }
        }
    },
];

// 4. Concurrent writers, same room.
$scenarios['concurrent_same'] = [
    'name' => 'Concurrent append, same room (' . $size['concurrent_workers'] . ' workers)',
    'ops' => $size['concurrent_workers'] * $size['concurrent_per'],
    'concurrent' => true,
    'workers' => $size['concurrent_workers'],
    'run' => function (SyncStorage $s, int $workerId) use ($size): void {
        $room = 'bench/concur-same@default';
        $blob = payload($size['update_bytes']);
        for ($i = 0; $i < $size['concurrent_per']; $i++) {
            $s->appendUpdate($room, $blob, "w{$workerId}");
        }
    },
];

// 5. Concurrent writers, different rooms.
$scenarios['concurrent_diff'] = [
    'name' => 'Concurrent append, separate rooms (' . $size['concurrent_workers'] . ' workers)',
    'ops' => $size['concurrent_workers'] * $size['concurrent_per'],
    'concurrent' => true,
    'workers' => $size['concurrent_workers'],
    'run' => function (SyncStorage $s, int $workerId) use ($size): void {
        $room = 'bench/concur-diff-' . $workerId . '@default';
        $blob = payload($size['update_bytes']);
        for ($i = 0; $i < $size['concurrent_per']; $i++) {
            $s->appendUpdate($room, $blob, "w{$workerId}");
        }
    },
];

// 6. Reader-while-writing: 1 writer + N readers polling.
$scenarios['reader_writer'] = [
    'name' => '1 writer + ' . $size['reader_count'] . ' readers polling',
    'ops' => $size['reader_writer_ops'],
    'concurrent' => true,
    'workers' => 1 + $size['reader_count'],
    'run' => function (SyncStorage $s, int $workerId) use ($size): void {
        $room = 'bench/rw@default';
        $blob = payload($size['update_bytes']);
        if ($workerId === 0) {
            // Writer.
            for ($i = 0; $i < $size['reader_writer_ops']; $i++) {
                $s->appendUpdate($room, $blob, 'writer');
            }
        } else {
            // Reader: poll until cursor stabilises with no new data after a few
            // empty pulls in a row, capped at reader_writer_ops iterations.
            $cursor = 0;
            $emptyStreak = 0;
            for ($i = 0; $i < $size['reader_writer_ops']; $i++) {
                $res = $s->getUpdatesSince($room, $cursor);
                if ($res['offset'] === $cursor) {
                    if (++$emptyStreak > 50) {
                        break;
                    }
                    usleep(100);
                } else {
                    $emptyStreak = 0;
                    $cursor = $res['offset'];
                }
            }
        }
    },
];

// 7. Snapshot write+read cycle.
$scenarios['snapshot'] = [
    'name' => 'Snapshot write+read cycle',
    'ops' => $size['snapshot_iters'] * 2, // 1 write + 1 read per iter
    'concurrent' => false,
    'run' => function (SyncStorage $s) use ($size): void {
        $room = 'bench/snap@default';
        $snap = payload($size['snapshot_bytes']);
        $sv = payload(64);
        for ($i = 0; $i < $size['snapshot_iters']; $i++) {
            $s->writeSnapshot($room, $snap, $sv);
            $s->loadSnapshot($room);
        }
    },
];

// ────────────────────────────────────────────────────────────────────────────
// Runner
// ────────────────────────────────────────────────────────────────────────────

/**
 * Time one concurrent scenario by forking $workers children. Each child
 * builds its own storage instance from the shared root and runs the
 * scenario closure with its worker id. Returns total wall-clock seconds.
 */
function runConcurrent(string $backend, string $root, int $workers, callable $fn): float
{
    $factory = static function () use ($backend, $root): SyncStorage {
        return $backend === 'sqlite'
            ? new SqliteSyncStorage($root)
            : new FileSyncStorage($root);
    };

    $start = hrtime(true);
    $pids = [];
    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // Child gets a fresh storage instance (no shared PDO/file handles).
            $storage = $factory();
            try {
                $fn($storage, $w);
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, "worker {$w} ({$backend}) failed: " . $e->getMessage() . "\n");
                exit(1);
            }
        }
        $pids[] = $pid;
    }

    $failed = false;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            $failed = true;
        }
    }
    $end = hrtime(true);
    if ($failed) {
        throw new RuntimeException("one or more workers ({$backend}) exited non-zero");
    }
    return ($end - $start) / 1e9;
}

function benchConcurrent(int $runs, int $workers, callable $fn): array
{
    $fileTimes = [];
    $sqliteTimes = [];
    for ($i = 0; $i < $runs; $i++) {
        $base = sys_get_temp_dir() . '/sync-bench-concur-' . bin2hex(random_bytes(4));
        $fileRoot = $base . '/file';
        $sqliteRoot = $base . '/sqlite';
        mkdir($fileRoot, 0755, true);
        mkdir($sqliteRoot, 0755, true);

        try {
            $fileTimes[] = runConcurrent('file', $fileRoot, $workers, $fn);
            $sqliteTimes[] = runConcurrent('sqlite', $sqliteRoot, $workers, $fn);
        } finally {
            rrm($base);
        }
    }
    return ['file' => median($fileTimes), 'sqlite' => median($sqliteTimes)];
}

// Run all scenarios.
$results = [];
foreach ($scenarios as $key => $sc) {
    if ($sc['concurrent'] && !$canFork) {
        $results[$key] = [
            'name' => $sc['name'],
            'skipped' => 'pcntl not available',
        ];
        continue;
    }

    if (!$opts['json']) {
        fwrite(STDERR, "[bench] {$sc['name']}\n");
    }

    if ($sc['concurrent']) {
        $times = benchConcurrent($opts['runs'], $sc['workers'], $sc['run']);
    } else {
        $closure = $sc['run'];
        $setup = $sc['setup'] ?? null;
        $times = bench($opts['runs'], static function (SyncStorage $s) use ($closure, $setup): void {
            if ($setup !== null) {
                $setup($s);
            }
            $closure($s);
        });
    }

    $results[$key] = [
        'name' => $sc['name'],
        'ops' => $sc['ops'],
        'file_seconds' => $times['file'],
        'sqlite_seconds' => $times['sqlite'],
        'file_ops_per_sec' => $sc['ops'] / max($times['file'], 1e-9),
        'sqlite_ops_per_sec' => $sc['ops'] / max($times['sqlite'], 1e-9),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// Output
// ────────────────────────────────────────────────────────────────────────────

if ($opts['json']) {
    echo json_encode([
        'config' => [
            'quick' => $opts['quick'],
            'runs' => $opts['runs'],
            'php_version' => PHP_VERSION,
            'pdo_sqlite' => extension_loaded('pdo_sqlite') ? phpversion('pdo_sqlite') : null,
            'sqlite_lib' => class_exists('PDO') ? (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_CLIENT_VERSION) : null,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
        ],
        'sizes' => $size,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$nameW = 50;
$colW = 12;
$bar = str_repeat('─', $nameW + $colW * 3 + 8);

echo "\n";
echo "PHP " . PHP_VERSION . " / pdo_sqlite " . (phpversion('pdo_sqlite') ?: 'n/a')
    . " / SAPI " . PHP_SAPI . " / OS " . PHP_OS . "\n";
echo "Runs: {$opts['runs']} (median)" . ($opts['quick'] ? '  [--quick]' : '') . "\n";
echo $bar . "\n";
printf("%-{$nameW}s %{$colW}s %{$colW}s %{$colW}s\n", 'Scenario', 'File ops/s', 'SQLite ops/s', 'Ratio');
echo $bar . "\n";
foreach ($results as $r) {
    if (isset($r['skipped'])) {
        printf("%-{$nameW}s %{$colW}s %{$colW}s %{$colW}s\n", $r['name'], '—', '—', $r['skipped']);
        continue;
    }
    printf(
        "%-{$nameW}s %{$colW}s %{$colW}s %{$colW}s\n",
        $r['name'],
        fmt($r['file_ops_per_sec']),
        fmt($r['sqlite_ops_per_sec']),
        ratio($r['sqlite_ops_per_sec'], $r['file_ops_per_sec'])
    );
}
echo $bar . "\n";
echo "Ratio = SQLite ops/sec ÷ File ops/sec. Higher = SQLite faster.\n\n";
