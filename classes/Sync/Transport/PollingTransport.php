<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Transport;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Message\AwarenessMessage;
use Grav\Plugin\Sync\Message\BroadcastMessage;
use Grav\Plugin\Sync\Message\CrdtMessage;
use Grav\Plugin\Sync\Message\Message;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\Storage\BroadcastStorage;
use Grav\Plugin\Sync\SyncStorage;

/**
 * The built-in always-available transport.
 *
 * Polling is conceptually "the default storage backend": it doesn't push
 * anything to clients, it just persists into the per-message-type stores
 * and the JS client pulls on a timer.
 *
 *   - Crdt:      append into SyncStorage (the existing CRDT log).
 *   - Broadcast: append into BroadcastStorage (the new TTL ring buffer)
 *                if the channel has storage enabled. If it doesn't,
 *                publishing via polling is a no-op (the message is gone
 *                unless a higher-priority push transport carried it).
 *   - Awareness: heartbeat into PresenceStore. The clientId for the
 *                presence entry comes from the message's sourceClientId.
 *                Awareness via polling is only useful in conjunction with
 *                the existing /presence endpoint; this branch exists so
 *                facade::publish() works for awareness even when no push
 *                transport is registered.
 */
final class PollingTransport implements TransportInterface
{
    public function __construct(
        private readonly Grav $grav,
    ) {
    }

    public function id(): string
    {
        return 'polling';
    }

    public function name(): string
    {
        return 'HTTP Polling';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportedMessageTypes(): array
    {
        return [
            MessageType::Crdt->value,
            MessageType::Broadcast->value,
            MessageType::Awareness->value,
        ];
    }

    public function publish(Channel $channel, Message $message): void
    {
        if ($message->type() !== $channel->messageType) {
            // Defensive: the facade already validates this, but a custom
            // transport caller could bypass the facade.
            return;
        }

        switch (true) {
            case $message instanceof CrdtMessage:
                /** @var SyncStorage $storage */
                $storage = $this->grav['sync_storage'];
                $storage->appendUpdate($channel->id, $message->bytes, $message->clientId);
                return;

            case $message instanceof BroadcastMessage:
                if (!$channel->hasBroadcastStorage()) {
                    return;
                }
                /** @var BroadcastStorage $storage */
                $storage = $this->grav['sync_broadcast_storage'];
                $storage->append(
                    $channel->id,
                    $message,
                    $channel->broadcastTtlSeconds,
                    $channel->broadcastMaxMessages
                );
                return;

            case $message instanceof AwarenessMessage:
                /** @var PresenceStore $presence */
                $presence = $this->grav['sync_presence'];
                $presence->heartbeat(
                    $channel->id,
                    $message->sourceClientId,
                    null,
                    $message->payload
                );
                return;
        }
    }

    public function clientConfig(Channel $channel, ?UserInterface $user): array
    {
        /** @var Config $config */
        $config = $this->grav['config'];
        $idle = (int)$config->get('plugins.sync.polling.idle_interval_ms', 4000);
        $active = (int)$config->get('plugins.sync.polling.active_interval_ms', 1000);

        return [
            'transport' => 'polling',
            'channel' => $channel->id,
            'pullUrl' => '/sync/channels/' . rawurlencode($channel->id) . '/pull',
            'publishUrl' => '/sync/channels/' . rawurlencode($channel->id) . '/publish',
            'idleIntervalMs' => $idle,
            'activeIntervalMs' => $active,
        ];
    }

    public function priority(): int
    {
        return 0;
    }
}
