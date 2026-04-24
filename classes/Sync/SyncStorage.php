<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

/**
 * Storage abstraction for Yjs collaboration data.
 *
 * Dumb-server semantics: implementations store opaque binary Yjs updates
 * (append-only log) plus an optional squashed snapshot. No CRDT knowledge
 * required in PHP.
 *
 * Offsets are opaque byte/sequence cursors — clients pass back whatever
 * they received on the previous pull.
 */
interface SyncStorage
{
    /**
     * Append a binary Yjs update to the room's log.
     *
     * @param string $roomId Canonical room id (see RoomRegistry).
     * @param string $update Raw binary Yjs update bytes.
     * @param string|null $clientId Originating client id, for provenance only.
     * @return int New absolute log offset (size in bytes after append).
     */
    public function appendUpdate(string $roomId, string $update, ?string $clientId = null): int;

    /**
     * Read all updates after the given offset.
     *
     * @return array{updates: list<string>, offset: int, size: int}
     *         updates: binary Yjs update blobs in order
     *         offset: new cursor for next pull
     *         size: current total log size
     */
    public function getUpdatesSince(string $roomId, int $offset): array;

    /**
     * Current log size in bytes (cheap; used for max-log-bytes squash trigger).
     */
    public function logSize(string $roomId): int;

    /**
     * Load the squashed snapshot for a room, if one exists.
     *
     * @return array{snapshot: string, stateVector: string, updatedAt: int}|null
     */
    public function loadSnapshot(string $roomId): ?array;

    /**
     * Write the squashed snapshot for a room.
     * Atomic (rename-swap).
     */
    public function writeSnapshot(string $roomId, string $snapshot, string $stateVector): void;

    /**
     * Truncate updates at/before the given offset. Called after a successful
     * squash once all connected clients have acknowledged the snapshot.
     */
    public function truncateUpdates(string $roomId, int $beforeOffset): void;

    /**
     * Remove all storage for a room. Called when the source page is deleted.
     */
    public function deleteRoom(string $roomId): void;

    /**
     * Does this room have any storage (log or snapshot)?
     */
    public function exists(string $roomId): bool;
}
