<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Message;

use Grav\Plugin\Sync\MessageType;

/**
 * A binary CRDT update (typically a Yjs encodeStateAsUpdate frame).
 *
 * The bytes are opaque to sync; ordering and convergence are the CRDT
 * library's responsibility on the client side. Sync only appends to the
 * room log and replays it back on demand.
 */
final class CrdtMessage implements Message
{
    public function __construct(
        public readonly string $bytes,
        public readonly ?string $clientId = null,
    ) {
        if ($bytes === '') {
            throw new \InvalidArgumentException('CrdtMessage bytes cannot be empty.');
        }
    }

    public function type(): MessageType
    {
        return MessageType::Crdt;
    }

    public function toArray(): array
    {
        return [
            'type' => 'crdt',
            'update' => base64_encode($this->bytes),
            'clientId' => $this->clientId,
        ];
    }
}
