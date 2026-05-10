<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Transport;

use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Message\Message;

/**
 * A pluggable backend that delivers messages to subscribers and signals
 * them when something has been published. Sync core ships with a
 * PollingTransport; sync-mercure (Round 4) plugs in an SSE transport;
 * sync-ably (also Round 4) plugs in a managed-pubsub transport.
 *
 * Implementations register themselves by listening to the
 * onSyncRegisterTransports event and calling
 * $grav['sync_transports']->register($this).
 *
 * Sync's facade picks the highest-priority *available* transport whose
 * supportedMessageTypes() includes the channel's MessageType. Polling
 * always remains registered as a priority-0 fallback so the system has a
 * universal floor.
 */
interface TransportInterface
{
    /** Stable id, e.g. 'polling' | 'mercure' | 'ably'. */
    public function id(): string;

    /** Human-readable name surfaced in the capabilities response. */
    public function name(): string;

    /** Whether this transport is currently usable (config + connectivity). */
    public function isAvailable(): bool;

    /**
     * Which message kinds this transport can carry.
     *
     * @return list<string> Strings from MessageType::value (e.g. 'crdt').
     */
    public function supportedMessageTypes(): array;

    /**
     * Server-side delivery hook. Called by Sync::publish() for every
     * available transport that supports the channel's message type.
     *
     * For storage-bearing transports (polling), this is where the
     * persistence call happens. For push transports (Mercure, Ably), this
     * is where the hub call happens.
     */
    public function publish(Channel $channel, Message $message): void;

    /**
     * Per-subscriber config the JS client needs in order to subscribe.
     * Returned shape is transport-specific (urls, tokens, topic strings).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(Channel $channel, ?UserInterface $user): array;

    /**
     * Higher = preferred. Polling is 0 (always-available fallback). Mercure
     * and Ably default to ~50; future custom transports can outbid.
     */
    public function priority(): int;
}
