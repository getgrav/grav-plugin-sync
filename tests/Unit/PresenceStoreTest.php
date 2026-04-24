<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Tests\Unit;

use Grav\Plugin\Sync\PresenceStore;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class PresenceStoreTest extends TestCase
{
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->cache = $this->makeCache();
    }

    private function makeCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, array{value: mixed, expiresAt: int}> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                if (!isset($this->store[$key])) {
                    return $default;
                }
                if ($this->store[$key]['expiresAt'] < time()) {
                    unset($this->store[$key]);
                    return $default;
                }
                return $this->store[$key]['value'];
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $ttlSecs = $ttl instanceof \DateInterval
                    ? (int)$ttl->format('%s') + 60 * (int)$ttl->format('%i')
                    : (int)($ttl ?? 3600);
                $this->store[$key] = [
                    'value' => $value,
                    'expiresAt' => time() + $ttlSecs,
                ];
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->store = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $out = [];
                foreach ($keys as $k) {
                    $out[$k] = $this->get($k, $default);
                }
                return $out;
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $k => $v) {
                    $this->set($k, $v, $ttl);
                }
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $k) {
                    unset($this->store[$k]);
                }
                return true;
            }

            public function has(string $key): bool
            {
                return $this->get($key, '__missing__') !== '__missing__';
            }
        };
    }

    public function test_heartbeat_adds_peer(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'client1', 'alice', ['cursor' => 10]);
        $peers = $p->peers('room-a');
        $this->assertCount(1, $peers);
        $this->assertSame('client1', $peers[0]['clientId']);
        $this->assertSame('alice', $peers[0]['user']);
        $this->assertSame(['cursor' => 10], $peers[0]['meta']);
    }

    public function test_multiple_peers(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'c1', 'alice');
        $p->heartbeat('room-a', 'c2', 'bob');
        $p->heartbeat('room-b', 'c3', 'carol');

        $this->assertSame(2, $p->peerCount('room-a'));
        $this->assertSame(1, $p->peerCount('room-b'));
    }

    public function test_leave_removes_peer(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'c1', 'alice');
        $p->heartbeat('room-a', 'c2', 'bob');
        $p->leave('room-a', 'c1');
        $peers = $p->peers('room-a');
        $this->assertCount(1, $peers);
        $this->assertSame('c2', $peers[0]['clientId']);
    }

    public function test_heartbeat_refreshes_existing(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'c1', 'alice', ['cursor' => 5]);
        $p->heartbeat('room-a', 'c1', 'alice', ['cursor' => 99]);
        $peers = $p->peers('room-a');
        $this->assertCount(1, $peers);
        $this->assertSame(99, $peers[0]['meta']['cursor']);
    }

    public function test_expired_peer_purged_on_read(): void
    {
        // Negative TTL to simulate an already-expired entry.
        $p = new PresenceStore($this->cache, ttlSeconds: -1);
        $p->heartbeat('room-a', 'c1', 'alice');
        $peers = $p->peers('room-a');
        $this->assertCount(0, $peers);
        $this->assertTrue($p->isIdle('room-a'));
    }

    public function test_is_idle_for_empty_room(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $this->assertTrue($p->isIdle('never-touched'));
    }

    public function test_clear_removes_all_presence(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'c1', 'alice');
        $p->heartbeat('room-a', 'c2', 'bob');
        $p->clear('room-a');
        $this->assertTrue($p->isIdle('room-a'));
    }

    public function test_age_reflects_last_seen(): void
    {
        $p = new PresenceStore($this->cache, ttlSeconds: 30);
        $p->heartbeat('room-a', 'c1', 'alice');
        $peers = $p->peers('room-a');
        $this->assertGreaterThanOrEqual(0, $peers[0]['age']);
        $this->assertLessThan(2, $peers[0]['age']);
    }
}
