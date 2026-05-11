<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Plugin\Sync\Storage\SqliteSyncStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of ConcurrentAppendTest but for the SQLite backend. Each child
 * process gets its own PDO handle (sqlite handles must not cross fork
 * boundaries) and races to append updates. WAL + busy_timeout + the
 * BEGIN IMMEDIATE pattern in appendUpdate together guarantee every update
 * lands and end_offset stays strictly monotonic.
 */
#[Group('concurrency')]
final class SqliteConcurrentAppendTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->dataRoot = sys_get_temp_dir() . '/sync-sqlite-concur-' . bin2hex(random_bytes(4));
        mkdir($this->dataRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dataRoot) && is_dir($this->dataRoot)) {
            foreach (glob($this->dataRoot . '/*/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($this->dataRoot . '/*') ?: [] as $d) {
                @rmdir($d);
            }
            @rmdir($this->dataRoot);
        }
    }

    public function test_concurrent_appends_preserve_all_updates(): void
    {
        $room = 'concur@default';
        $workers = 8;
        $updatesPerWorker = 25;
        $expected = [];

        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // Child: brand-new SqliteSyncStorage (no inherited PDO handle).
                $storage = new SqliteSyncStorage($this->dataRoot);
                for ($i = 0; $i < $updatesPerWorker; $i++) {
                    $payload = sprintf('w%02d-i%02d-%s', $w, $i, str_repeat('x', 16));
                    $storage->appendUpdate($room, $payload, "w{$w}");
                }
                exit(0);
            }
            $pids[] = $pid;
            for ($i = 0; $i < $updatesPerWorker; $i++) {
                $expected[] = sprintf('w%02d-i%02d-%s', $w, $i, str_repeat('x', 16));
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'worker exited non-zero');
        }

        $storage = new SqliteSyncStorage($this->dataRoot);
        $res = $storage->getUpdatesSince($room, 0);

        $this->assertCount($workers * $updatesPerWorker, $res['updates']);
        sort($expected);
        $got = $res['updates'];
        sort($got);
        $this->assertSame($expected, $got);
    }
}
