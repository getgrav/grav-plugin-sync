<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Plugin\Sync\Storage\FileSyncStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileSyncStorageTest extends TestCase
{
    private string $pagesRoot;

    protected function setUp(): void
    {
        $this->pagesRoot = sys_get_temp_dir() . '/sync-test-' . bin2hex(random_bytes(4));
        mkdir($this->pagesRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->pagesRoot);
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            if ($f->isDir()) {
                rmdir($f->getPathname());
            } else {
                unlink($f->getPathname());
            }
        }
        rmdir($dir);
    }

    private function storage(): FileSyncStorage
    {
        return new FileSyncStorage($this->pagesRoot);
    }

    // ------------------------------------------------------------------

    public function test_round_trip_single_update(): void
    {
        $s = $this->storage();
        $room = 'blog/hello@default';
        $update = random_bytes(128);

        $size = $s->appendUpdate($room, $update, 'c1');
        $this->assertSame(4 + 128, $size);

        $res = $s->getUpdatesSince($room, 0);
        $this->assertCount(1, $res['updates']);
        $this->assertSame($update, $res['updates'][0]);
        $this->assertSame($size, $res['offset']);
        $this->assertSame($size, $res['size']);
    }

    public function test_round_trip_multiple_updates(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $updates = [
            str_repeat("\x01", 10),
            str_repeat("\x02", 50),
            str_repeat("\x03", 200),
        ];
        foreach ($updates as $u) {
            $s->appendUpdate($room, $u);
        }

        $res = $s->getUpdatesSince($room, 0);
        $this->assertSame($updates, $res['updates']);
    }

    public function test_incremental_pull_advances_cursor(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->appendUpdate($room, 'A');
        $first = $s->getUpdatesSince($room, 0);
        $this->assertSame(['A'], $first['updates']);

        $s->appendUpdate($room, 'BB');
        $s->appendUpdate($room, 'CCC');
        $second = $s->getUpdatesSince($room, $first['offset']);
        $this->assertSame(['BB', 'CCC'], $second['updates']);

        // Pulling again from the new cursor should be empty.
        $third = $s->getUpdatesSince($room, $second['offset']);
        $this->assertSame([], $third['updates']);
        $this->assertSame($second['offset'], $third['offset']);
    }

    public function test_empty_room_pull_returns_zero(): void
    {
        $s = $this->storage();
        $res = $s->getUpdatesSince('nonexistent@default', 0);
        $this->assertSame([], $res['updates']);
        $this->assertSame(0, $res['offset']);
        $this->assertSame(0, $res['size']);
    }

    public function test_refuses_empty_update(): void
    {
        $s = $this->storage();
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('foo@default', '');
    }

    public function test_refuses_oversized_update(): void
    {
        $s = new FileSyncStorage($this->pagesRoot, maxUpdateBytes: 100);
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('foo@default', str_repeat('x', 101));
    }

    public function test_path_traversal_blocked(): void
    {
        $s = $this->storage();
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('../evil@default', 'payload');
    }

    public function test_invalid_segment_blocked(): void
    {
        $s = $this->storage();
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('foo/bar with spaces@default', 'payload');
    }

    public function test_default_template_when_no_at(): void
    {
        $s = $this->storage();
        $s->appendUpdate('plain-route', 'hi');
        $this->assertFileExists($this->pagesRoot . '/plain-route/.sync/default.log');
    }

    public function test_language_aware_room(): void
    {
        $s = $this->storage();
        $s->appendUpdate('blog/hello.fr@default', 'french');
        $s->appendUpdate('blog/hello@default', 'english');
        // Both live in the same folder but under different ids.
        $this->assertFileExists($this->pagesRoot . '/blog/hello.fr/.sync/default.log');
        $this->assertFileExists($this->pagesRoot . '/blog/hello/.sync/default.log');
    }

    public function test_snapshot_round_trip(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $snap = random_bytes(256);
        $sv = random_bytes(32);
        $s->writeSnapshot($room, $snap, $sv);
        $loaded = $s->loadSnapshot($room);
        $this->assertNotNull($loaded);
        $this->assertSame($snap, $loaded['snapshot']);
        $this->assertSame($sv, $loaded['stateVector']);
        $this->assertGreaterThan(0, $loaded['updatedAt']);
    }

    public function test_snapshot_atomic_rename(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->writeSnapshot($room, 'v1', 'sv1');
        $s->writeSnapshot($room, 'v2', 'sv2');
        $loaded = $s->loadSnapshot($room);
        $this->assertSame('v2', $loaded['snapshot']);
        $this->assertSame('sv2', $loaded['stateVector']);
        // No stale .tmp files left behind
        $tmps = glob($this->pagesRoot . '/foo/.sync/*.tmp.*');
        $this->assertEmpty($tmps);
    }

    public function test_truncate_full(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->appendUpdate($room, 'aaa');
        $s->appendUpdate($room, 'bbb');
        $beforeSize = $s->logSize($room);
        $s->truncateUpdates($room, $beforeSize);
        $this->assertSame(0, $s->logSize($room));
        $this->assertSame([], $s->getUpdatesSince($room, 0)['updates']);
    }

    public function test_truncate_partial(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->appendUpdate($room, 'aaa');
        $boundary = $s->logSize($room); // offset right after first update
        $s->appendUpdate($room, 'bbbb');
        $s->appendUpdate($room, 'ccccc');

        $s->truncateUpdates($room, $boundary);
        $res = $s->getUpdatesSince($room, 0);
        $this->assertSame(['bbbb', 'ccccc'], $res['updates']);
    }

    public function test_truncate_noop_on_missing_log(): void
    {
        $s = $this->storage();
        $s->truncateUpdates('nonexistent@default', 100);
        // Should not throw.
        $this->assertTrue(true);
    }

    public function test_delete_room_removes_all(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->appendUpdate($room, 'aaa');
        $s->writeSnapshot($room, 'snap', 'sv');
        $this->assertTrue($s->exists($room));
        $s->deleteRoom($room);
        $this->assertFalse($s->exists($room));
    }

    public function test_exists(): void
    {
        $s = $this->storage();
        $this->assertFalse($s->exists('foo@default'));
        $s->appendUpdate('foo@default', 'x');
        $this->assertTrue($s->exists('foo@default'));
    }

    public function test_log_size_matches_filesize(): void
    {
        $s = $this->storage();
        $s->appendUpdate('foo@default', 'hello');
        $expected = 4 + 5;
        $this->assertSame($expected, $s->logSize('foo@default'));
    }

    public function test_corrupted_log_stops_at_last_valid_entry(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->appendUpdate($room, 'AAAA');
        // Append garbage (no length header).
        $log = $this->pagesRoot . '/foo/.sync/default.log';
        file_put_contents($log, 'XY', FILE_APPEND);
        $res = $s->getUpdatesSince($room, 0);
        // First update is still parseable; trailing garbage is ignored.
        $this->assertSame(['AAAA'], $res['updates']);
    }

    public function test_pull_beyond_end_returns_empty_with_size(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $size = $s->appendUpdate($room, 'hello');
        $res = $s->getUpdatesSince($room, $size + 999);
        $this->assertSame([], $res['updates']);
        $this->assertSame($size, $res['offset']);
        $this->assertSame($size, $res['size']);
    }
}
