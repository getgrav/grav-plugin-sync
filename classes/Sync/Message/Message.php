<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Message;

use Grav\Plugin\Sync\MessageType;

/**
 * Common interface for the three message kinds carried over sync.
 *
 * Implementations are immutable readonly value objects. The interface only
 * exposes type discrimination plus a JSON projection for transports that
 * carry messages across the wire as JSON (polling pull responses,
 * broadcast file storage, future SSE/WebSocket frames).
 */
interface Message
{
    /** Which message kind this is (used to validate against Channel::messageType). */
    public function type(): MessageType;

    /**
     * Wire projection for transports that need a JSON-ready array. Binary
     * payloads (e.g. CrdtMessage::$bytes) are base64-encoded; clientId and
     * timestamps surface as scalar fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
