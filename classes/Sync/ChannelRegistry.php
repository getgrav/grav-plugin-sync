<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

/**
 * In-memory map of registered channels keyed by id.
 *
 * Single-request scope: PHP rebuilds this on every request via the
 * onSyncRegisterChannels event firing in onPluginsInitialized. We don't
 * need locking; there's only one writer per process.
 *
 * Re-registering the same id silently overwrites the previous entry. This
 * is intentional: it lets sync's own legacy CRDT auto-registration coexist
 * with explicit channel registrations from consumer plugins.
 */
final class ChannelRegistry
{
    /** @var array<string, Channel> */
    private array $channels = [];

    public function register(Channel $channel): void
    {
        $this->channels[$channel->id] = $channel;
    }

    public function get(string $id): ?Channel
    {
        return $this->channels[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->channels[$id]);
    }

    /** @return array<string, Channel> */
    public function all(): array
    {
        return $this->channels;
    }
}
