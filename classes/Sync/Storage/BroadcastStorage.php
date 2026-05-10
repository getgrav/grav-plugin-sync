<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Storage;

use Grav\Plugin\Sync\Message\BroadcastMessage;

/**
 * Storage abstraction for broadcast-channel ring buffers.
 *
 * Distinct from SyncStorage (CRDT) because the access pattern is different:
 *   - per-channel ring buffer with TTL eviction
 *   - "messages since timestamp X" instead of "byte-offset since X"
 *   - JSON-shaped tuples instead of opaque binary blobs
 *
 * Implementations should be safe to call concurrently across processes.
 */
interface BroadcastStorage
{
    /**
     * Append a broadcast to the channel's ring buffer. May evict older
     * entries to enforce $maxMessages.
     */
    public function append(string $channelId, BroadcastMessage $message, int $ttlSeconds, int $maxMessages): void;

    /**
     * Return broadcasts for $channelId with timestamps strictly greater
     * than $sinceTimestamp (in milliseconds, matching BroadcastMessage::$timestamp).
     * Pass null to get the full retained buffer.
     *
     * Side-effect: expired entries (older than each entry's recorded TTL)
     * are pruned during the read.
     *
     * @return list<array{event: ?string, payload: array<string, mixed>, timestamp: int}>
     */
    public function since(string $channelId, ?int $sinceTimestamp): array;

    /**
     * Drop all stored broadcasts for $channelId.
     */
    public function clear(string $channelId): void;
}
