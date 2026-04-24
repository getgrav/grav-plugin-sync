<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

/**
 * Value object representing a single collaboration room.
 *
 * A room is a (page route, language) pair. The id is the canonical
 * string used as the storage key and in the API wire format.
 */
final class SyncRoom
{
    public function __construct(
        public readonly string $id,
        public readonly string $route,
        public readonly ?string $language = null,
        public readonly string $template = 'default',
    ) {
    }

    /**
     * Storage-safe token derived from the room id. Used for file names,
     * cache keys, etc.
     */
    public function storageKey(): string
    {
        // Replace any character that isn't a safe path segment with an
        // underscore; keep letters/digits/dash/dot/slash intact.
        return (string)preg_replace('/[^a-z0-9._\/-]/i', '_', $this->id);
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
