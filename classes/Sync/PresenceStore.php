<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

use Grav\Common\Cache as GravCache;
use Psr\SimpleCache\CacheInterface;

/**
 * Ephemeral presence/awareness registry.
 *
 * Backed by whatever PSR-16 cache Grav is configured with (file/apcu/redis/
 * memcache). Each room holds a map of clientId => ['user' => ..., 'meta' =>
 * ..., 'expiresAt' => unix]. Clients must heartbeat before their TTL runs
 * out or they get purged on next read.
 *
 * Read shape from peers():
 *   [ ['clientId' => '...', 'user' => '...', 'meta' => [...], 'age' => 3], ... ]
 */
final class PresenceStore
{
    private CacheInterface $cache;

    public function __construct(
        GravCache|CacheInterface $cache,
        private readonly int $ttlSeconds = 30,
    ) {
        $this->cache = $cache instanceof GravCache ? $cache->getSimpleCache() : $cache;
    }

    /**
     * Record/refresh a client's presence in a room.
     *
     * @param array<string, mixed> $meta  Arbitrary client-supplied payload
     *                                    (cursor pos, selection, color…).
     */
    public function heartbeat(string $roomId, string $clientId, ?string $user = null, array $meta = []): void
    {
        $map = $this->load($roomId);
        $now = time();
        $map[$clientId] = [
            'user' => $user,
            'meta' => $meta,
            'expiresAt' => $now + $this->ttlSeconds,
            'lastSeen' => $now,
        ];
        $this->save($roomId, $map);
    }

    /**
     * Remove a client from a room (explicit disconnect).
     */
    public function leave(string $roomId, string $clientId): void
    {
        $map = $this->load($roomId);
        if (!isset($map[$clientId])) {
            return;
        }
        unset($map[$clientId]);
        $this->save($roomId, $map);
    }

    /**
     * List active peers in a room. Side-effect: expired entries are purged.
     *
     * @return list<array{clientId: string, user: ?string, meta: array<string, mixed>, age: int}>
     */
    public function peers(string $roomId): array
    {
        $map = $this->load($roomId);
        $now = time();
        $live = [];
        $changed = false;
        foreach ($map as $cid => $entry) {
            if (($entry['expiresAt'] ?? 0) < $now) {
                $changed = true;
                continue;
            }
            $live[$cid] = $entry;
        }
        if ($changed) {
            $this->save($roomId, $live);
        }

        $out = [];
        foreach ($live as $cid => $entry) {
            $out[] = [
                'clientId' => $cid,
                'user' => $entry['user'] ?? null,
                'meta' => $entry['meta'] ?? [],
                'age' => max(0, $now - (int)($entry['lastSeen'] ?? $now)),
            ];
        }
        return $out;
    }

    /**
     * Count of active (non-expired) peers. Cheap — no purge.
     */
    public function peerCount(string $roomId): int
    {
        $map = $this->load($roomId);
        $now = time();
        $n = 0;
        foreach ($map as $entry) {
            if (($entry['expiresAt'] ?? 0) >= $now) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Is the room idle (no live peers)?
     */
    public function isIdle(string $roomId): bool
    {
        return $this->peerCount($roomId) === 0;
    }

    /**
     * Clear all presence for a room (e.g. on page delete).
     */
    public function clear(string $roomId): void
    {
        $this->cache->delete($this->key($roomId));
    }

    // ------------------------------------------------------------------

    private function key(string $roomId): string
    {
        // PSR-16 forbids certain chars in keys. hash to be safe.
        return 'sync.presence.' . sha1($roomId);
    }

    /**
     * @return array<string, array{user: ?string, meta: array<string, mixed>, expiresAt: int, lastSeen: int}>
     */
    private function load(string $roomId): array
    {
        $raw = $this->cache->get($this->key($roomId));
        if (!is_array($raw)) {
            return [];
        }
        return $raw;
    }

    /**
     * @param array<string, array{user: ?string, meta: array<string, mixed>, expiresAt: int, lastSeen: int}> $map
     */
    private function save(string $roomId, array $map): void
    {
        if ($map === []) {
            $this->cache->delete($this->key($roomId));
            return;
        }
        // Store with slightly longer TTL than any single entry so the map
        // itself doesn't evaporate between heartbeats.
        $this->cache->set($this->key($roomId), $map, $this->ttlSeconds * 3);
    }
}
