<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Plugin\Sync\Storage\SqliteSyncStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqliteSyncStorageTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->dataRoot = sys_get_temp_dir() . '/sync-sqlite-test-' . bin2hex(random_bytes(4));
        mkdir($this->dataRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->dataRoot);
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

    private function storage(): SqliteSyncStorage
    {
        return new SqliteSyncStorage($this->dataRoot);
    }

    private function roomDir(string $route): string
    {
        return $this->dataRoot . '/' . md5($route);
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

    public function test_round_trip_multiple_updates_preserves_order(): void
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

        $third = $s->getUpdatesSince($room, $second['offset']);
        $this->assertSame([], $third['updates']);
        $this->assertSame($second['offset'], $third['offset']);
    }

    public function test_cursor_matches_file_backend_byte_layout(): void
    {
        // appendUpdate returns prev_size + 4 + len(update), same as the
        // file backend's byte position. This makes the cursor opaque-but-
        // portable across the two backends.
        $s = $this->storage();
        $room = 'foo@default';
        $a = $s->appendUpdate($room, str_repeat('a', 10));
        $b = $s->appendUpdate($room, str_repeat('b', 20));
        $c = $s->appendUpdate($room, str_repeat('c', 5));
        $this->assertSame(4 + 10, $a);
        $this->assertSame($a + 4 + 20, $b);
        $this->assertSame($b + 4 + 5, $c);
        $this->assertSame($c, $s->logSize($room));
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
        $s = new SqliteSyncStorage($this->dataRoot, maxUpdateBytes: 100);
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('foo@default', str_repeat('x', 101));
    }

    public function test_init_seeds_when_empty(): void
    {
        $s = $this->storage();
        $room = 'fresh@default';
        $seed = random_bytes(32);

        $res = $s->initIfEmpty($room, $seed);
        $this->assertTrue($res['seeded']);
        $this->assertSame(4 + 32, $res['size']);

        $pull = $s->getUpdatesSince($room, 0);
        $this->assertCount(1, $pull['updates']);
        $this->assertSame($seed, $pull['updates'][0]);
    }

    public function test_init_refuses_when_log_exists(): void
    {
        $s = $this->storage();
        $room = 'taken@default';
        $first = random_bytes(16);
        $second = random_bytes(16);

        $r1 = $s->initIfEmpty($room, $first);
        $this->assertTrue($r1['seeded']);

        $r2 = $s->initIfEmpty($room, $second);
        $this->assertFalse($r2['seeded']);
        $this->assertSame($r1['size'], $r2['size']);

        $pull = $s->getUpdatesSince($room, 0);
        $this->assertCount(1, $pull['updates']);
        $this->assertSame($first, $pull['updates'][0]);
    }

    public function test_init_refuses_when_appendUpdate_already_ran(): void
    {
        $s = $this->storage();
        $room = 'append-first@default';
        $s->appendUpdate($room, random_bytes(16));

        $res = $s->initIfEmpty($room, random_bytes(16));
        $this->assertFalse($res['seeded']);
    }

    public function test_init_refuses_empty_seed(): void
    {
        $s = $this->storage();
        $this->expectException(RuntimeException::class);
        $s->initIfEmpty('e@default', '');
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

    public function test_malformed_room_id_rejected(): void
    {
        $s = $this->storage();
        $this->expectException(RuntimeException::class);
        $s->appendUpdate('plain-route', 'hi');
    }

    public function test_writes_to_hashed_dir(): void
    {
        $s = $this->storage();
        $s->appendUpdate('plain-route@default', 'hi');
        $this->assertFileExists($this->roomDir('plain-route') . '/default.sqlite');
    }

    public function test_meta_json_records_route(): void
    {
        $s = $this->storage();
        $s->appendUpdate('blog/hello@default', 'hi');
        $meta = json_decode((string)file_get_contents($this->roomDir('blog/hello') . '/meta.json'), true);
        $this->assertSame('blog/hello', $meta['route'] ?? null);
        $this->assertGreaterThan(0, $meta['createdAt'] ?? 0);
    }

    public function test_language_aware_room(): void
    {
        $s = $this->storage();
        $s->appendUpdate('blog/hello@default@fr', 'french');
        $s->appendUpdate('blog/hello@default', 'english');
        $dir = $this->roomDir('blog/hello');
        $this->assertFileExists($dir . '/default.fr.sqlite');
        $this->assertFileExists($dir . '/default.sqlite');
        $this->assertSame(['french'], $s->getUpdatesSince('blog/hello@default@fr', 0)['updates']);
        $this->assertSame(['english'], $s->getUpdatesSince('blog/hello@default', 0)['updates']);
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

    public function test_snapshot_replace_keeps_only_latest(): void
    {
        $s = $this->storage();
        $room = 'foo@default';
        $s->writeSnapshot($room, 'v1', 'sv1');
        $s->writeSnapshot($room, 'v2', 'sv2');
        $loaded = $s->loadSnapshot($room);
        $this->assertSame('v2', $loaded['snapshot']);
        $this->assertSame('sv2', $loaded['stateVector']);
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
        $boundary = $s->logSize($room);
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
        $this->assertDirectoryDoesNotExist($this->roomDir('foo'));
    }

    public function test_exists(): void
    {
        $s = $this->storage();
        $this->assertFalse($s->exists('foo@default'));
        $s->appendUpdate('foo@default', 'x');
        $this->assertTrue($s->exists('foo@default'));
    }

    public function test_log_size_matches_byte_layout(): void
    {
        $s = $this->storage();
        $s->appendUpdate('foo@default', 'hello');
        $this->assertSame(4 + 5, $s->logSize('foo@default'));
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

    public function test_wal_mode_enabled(): void
    {
        $s = $this->storage();
        $s->appendUpdate('foo@default', 'x');
        $pdo = new \PDO('sqlite:' . $this->roomDir('foo') . '/default.sqlite');
        $mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertSame('wal', strtolower((string)$mode));
    }

    public function test_snapshot_independent_per_language(): void
    {
        $s = $this->storage();
        $s->writeSnapshot('blog/x@default', 'en-snap', 'en-sv');
        $s->writeSnapshot('blog/x@default@fr', 'fr-snap', 'fr-sv');
        $this->assertSame('en-snap', $s->loadSnapshot('blog/x@default')['snapshot']);
        $this->assertSame('fr-snap', $s->loadSnapshot('blog/x@default@fr')['snapshot']);
    }

    public function test_reopen_storage_sees_existing_data(): void
    {
        // New SqliteSyncStorage instance — no in-memory cached connection
        // is reused. Validates persistence across process boundaries.
        $room = 'persisted@default';
        $first = $this->storage();
        $first->appendUpdate($room, 'persisted-payload');

        $second = $this->storage();
        $res = $second->getUpdatesSince($room, 0);
        $this->assertSame(['persisted-payload'], $res['updates']);
    }
}
