<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

use Grav\Common\User\Interfaces\UserInterface;

/**
 * A registered pub/sub target. Channels are owned by a single plugin slug
 * and carry exactly one MessageType for their entire lifetime.
 *
 * Channel ids follow the convention "<owner-plugin-slug>:<scope>", e.g.
 * "editor-pro:page/blog/post-1@default" or "comments-pro:blog/post-1".
 * Sync doesn't enforce a specific scope format; it only treats the id as
 * an opaque key.
 *
 * Storage policy lives on the Channel for broadcast types:
 *   broadcastTtlSeconds  - how long entries are replayable to late joiners
 *                          (default 60s; 0 disables storage entirely)
 *   broadcastMaxMessages - hard cap on ring-buffer size, oldest evicted
 *                          first (default 50; 0 disables storage entirely)
 *
 * For Crdt and Awareness channels the broadcast options are ignored.
 */
final class Channel
{
    /**
     * @param callable(UserInterface|null, string): bool|null $authCallback
     *        Receives ($user, $action) where $action is 'subscribe' or
     *        'publish'. Return true to allow. If null, sync falls back to
     *        firing onSyncCheckAccess on the global event bus.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ownerPlugin,
        public readonly MessageType $messageType,
        public readonly mixed $authCallback = null,
        public readonly int $broadcastTtlSeconds = 60,
        public readonly int $broadcastMaxMessages = 50,
        public readonly array $metadata = [],
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Channel id cannot be empty.');
        }
        if ($ownerPlugin === '') {
            throw new \InvalidArgumentException('Channel ownerPlugin cannot be empty.');
        }
        if ($authCallback !== null && !is_callable($authCallback)) {
            throw new \InvalidArgumentException('Channel authCallback must be callable or null.');
        }
        if ($broadcastTtlSeconds < 0) {
            throw new \InvalidArgumentException('broadcastTtlSeconds must be >= 0.');
        }
        if ($broadcastMaxMessages < 0) {
            throw new \InvalidArgumentException('broadcastMaxMessages must be >= 0.');
        }
    }

    /**
     * True iff this is a broadcast channel with non-zero storage configured.
     * Used by the facade to decide whether to persist published messages
     * for late-joiner replay.
     */
    public function hasBroadcastStorage(): bool
    {
        return $this->messageType === MessageType::Broadcast
            && $this->broadcastTtlSeconds > 0
            && $this->broadcastMaxMessages > 0;
    }
}
