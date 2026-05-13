<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Message;

use Grav\Plugin\Sync\MessageType;

/**
 * Ephemeral presence/awareness payload (typing indicators, cursor positions,
 * selection ranges). Never persisted; only delivered to transports that are
 * currently listening.
 *
 * $sourceClientId identifies the originator so transports can avoid echoing
 * it back to the sender, and so PresenceStore can scope the entry to a
 * specific client.
 */
final class AwarenessMessage implements Message
{
    public function __construct(
        public readonly array $payload,
        public readonly string $sourceClientId,
    ) {
        if ($sourceClientId === '') {
            throw new \InvalidArgumentException('AwarenessMessage sourceClientId cannot be empty.');
        }
    }

    public function type(): MessageType
    {
        return MessageType::Awareness;
    }

    public function toArray(): array
    {
        return [
            'type' => 'awareness',
            'clientId' => $this->sourceClientId,
            'payload' => $this->payload,
        ];
    }
}
