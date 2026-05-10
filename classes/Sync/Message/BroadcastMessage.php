<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Message;

use Grav\Plugin\Sync\MessageType;

/**
 * A general-purpose pub/sub payload. The shape of $payload is up to the
 * owning plugin. Sync stores it verbatim in the broadcast ring buffer (if
 * the channel has TTL > 0) and forwards it to every available transport.
 *
 * $eventName is an optional sub-type discriminator the consumer plugin can
 * use to keep multiple logical events on a single channel (e.g.
 * "comment.created", "comment.deleted").
 *
 * $timestamp is auto-set to the current unix time (millisecond precision)
 * if not supplied. Used by the broadcast storage as the "since" cursor.
 */
final class BroadcastMessage implements Message
{
    public readonly int $timestamp;

    public function __construct(
        public readonly array $payload,
        public readonly ?string $eventName = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? (int)(microtime(true) * 1000);
    }

    public function type(): MessageType
    {
        return MessageType::Broadcast;
    }

    public function toArray(): array
    {
        return [
            'type' => 'broadcast',
            'event' => $this->eventName,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
        ];
    }
}
