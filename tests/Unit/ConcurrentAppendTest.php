<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Plugin\Sync\Storage\FileSyncStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises concurrent append safety by forking child processes that each
 * append a batch of updates. Validates all updates land intact and the
 * log is fully parseable.
 *
 * Skipped if pcntl is not available (non-CLI SAPIs, Windows, etc.).
 */
#[Group('concurrency')]
final class ConcurrentAppendTest extends TestCase
{
    private string $pagesRoot;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        $this->pagesRoot = sys_get_temp_dir() . '/sync-concur-' . bin2hex(random_bytes(4));
        mkdir($this->pagesRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pagesRoot) && is_dir($this->pagesRoot)) {
            foreach (glob($this->pagesRoot . '/*/.sync/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($this->pagesRoot . '/*/.sync') ?: [] as $d) {
                @rmdir($d);
            }
            foreach (glob($this->pagesRoot . '/*') ?: [] as $d) {
                @rmdir($d);
            }
            @rmdir($this->pagesRoot);
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
                // Child process.
                $storage = new FileSyncStorage($this->pagesRoot);
                for ($i = 0; $i < $updatesPerWorker; $i++) {
                    $payload = sprintf('w%02d-i%02d-%s', $w, $i, str_repeat('x', 16));
                    $storage->appendUpdate($room, $payload, "w{$w}");
                }
                exit(0);
            }
            $pids[] = $pid;
            // Parent collects expected payloads.
            for ($i = 0; $i < $updatesPerWorker; $i++) {
                $expected[] = sprintf('w%02d-i%02d-%s', $w, $i, str_repeat('x', 16));
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'worker exited non-zero');
        }

        $storage = new FileSyncStorage($this->pagesRoot);
        $res = $storage->getUpdatesSince($room, 0);

        $this->assertCount($workers * $updatesPerWorker, $res['updates']);
        sort($expected);
        $got = $res['updates'];
        sort($got);
        $this->assertSame($expected, $got);
    }
}
