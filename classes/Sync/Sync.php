<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Sync\Message\Message;
use Grav\Plugin\Sync\Storage\BroadcastStorage;
use Grav\Plugin\Sync\Transport\TransportInterface;
use Grav\Plugin\Sync\Transport\TransportRegistry;
use RocketTheme\Toolbox\Event\Event;

/**
 * Public consumer-facing facade exposed at $grav['sync'].
 *
 * Plugins use this object to:
 *   - register the channels they own (registerChannel)
 *   - publish messages to those channels (publish)
 *   - look up the JS-client config a subscriber needs (clientConfigFor)
 *   - delegate per-channel auth checks (checkAccess)
 *
 * The facade does not own the underlying transports or storage; it
 * delegates to the registered TransportInterface instances and the
 * sync_storage / sync_broadcast_storage / sync_presence services. Adding
 * a new transport is a matter of registering it via
 * onSyncRegisterTransports without touching this class.
 *
 * Backward compatibility: nothing on the existing CRDT path goes through
 * this facade. SyncController's existing pull/push/init/presence
 * endpoints continue to call SyncStorage and PresenceStore directly. The
 * facade becomes the new primary path for broadcast and awareness
 * channels owned by consumer plugins, plus the channel-scoped pull/publish
 * endpoints (see SyncController::channelPull / channelPublish).
 */
final class Sync
{
    public function __construct(
        private readonly Grav $grav,
        private readonly ChannelRegistry $channels,
        private readonly TransportRegistry $transports,
    ) {
    }

    public function registerChannel(Channel $channel): void
    {
        $this->channels->register($channel);
    }

    public function getChannel(string $id): ?Channel
    {
        return $this->channels->get($id);
    }

    /** @return array<string, Channel> */
    public function allChannels(): array
    {
        return $this->channels->all();
    }

    /**
     * Publish a message into a channel.
     *
     * Validates that the message type matches the channel type, then
     * fans out to every available transport that supports the type. For
     * broadcast channels with TTL > 0, the polling transport's publish
     * branch handles the persistence; this method does NOT separately
     * write to BroadcastStorage. Persistence is the polling transport's
     * job by definition (polling is the default storage backend).
     */
    public function publish(string $channelId, Message $message): void
    {
        $channel = $this->channels->get($channelId);
        if ($channel === null) {
            throw new \RuntimeException("sync: unknown channel '{$channelId}'");
        }
        if ($message->type() !== $channel->messageType) {
            throw new \InvalidArgumentException(sprintf(
                "sync: message type '%s' does not match channel '%s' type '%s'",
                $message->type()->value,
                $channelId,
                $channel->messageType->value
            ));
        }

        foreach ($this->transports->selectFor($channel) as $transport) {
            if (!$transport->isAvailable()) {
                continue;
            }
            $transport->publish($channel, $message);
        }
    }

    /**
     * Build the merged client-config payload a JS subscriber needs.
     *
     * Polling is always present as a fallback; the active transport (if
     * any push transport beats polling on priority and supports the
     * channel's type) is included alongside. The shape is:
     *   {
     *     channel: '...',
     *     messageType: 'crdt' | 'broadcast' | 'awareness',
     *     active: '<transport-id>',
     *     transports: { '<transport-id>': {...config...}, ... }
     *   }
     *
     * @return array<string, mixed>
     */
    public function clientConfigFor(string $channelId, ?UserInterface $user): array
    {
        $channel = $this->channels->get($channelId);
        if ($channel === null) {
            throw new \RuntimeException("sync: unknown channel '{$channelId}'");
        }

        $configs = [];
        $available = [];
        foreach ($this->transports->selectFor($channel) as $transport) {
            if (!$transport->isAvailable()) {
                continue;
            }
            $configs[$transport->id()] = $transport->clientConfig($channel, $user);
            $available[] = $transport;
        }

        $active = $this->activeTransportFor($channel);
        return [
            'channel' => $channel->id,
            'messageType' => $channel->messageType->value,
            'active' => $active?->id() ?? 'polling',
            'transports' => $configs,
        ];
    }

    /**
     * Delegate the per-channel auth check.
     *
     * If the channel was registered with an authCallback, call it directly.
     * Otherwise fire onSyncCheckAccess; subscribers (the owner plugin)
     * inspect the channel id and flip $event['allowed'] = true to grant.
     * Default deny if neither path resolves a true.
     *
     * $action is conventionally 'subscribe' or 'publish'; sync doesn't
     * enforce a specific vocabulary so consumer plugins can model whatever
     * their access model needs.
     */
    public function checkAccess(string $channelId, ?UserInterface $user, string $action): bool
    {
        $channel = $this->channels->get($channelId);
        if ($channel === null) {
            return false;
        }

        if ($channel->authCallback !== null) {
            return (bool)($channel->authCallback)($user, $action);
        }

        $event = new Event([
            'channel' => $channel,
            'channel_id' => $channel->id,
            'user' => $user,
            'action' => $action,
            'allowed' => null,
        ]);
        $this->grav->fireEvent('onSyncCheckAccess', $event);
        return $event['allowed'] === true;
    }

    /** @return array<string, TransportInterface> */
    public function transports(): array
    {
        return $this->transports->all();
    }

    /**
     * Highest-priority *available* transport whose supportedMessageTypes()
     * includes the channel's MessageType. Returns null if literally
     * nothing is registered (shouldn't happen because polling is always
     * registered, but the type system can't prove that).
     */
    public function activeTransportFor(Channel $channel): ?TransportInterface
    {
        foreach ($this->transports->selectFor($channel) as $t) {
            if ($t->isAvailable()) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Convenience accessor for the broadcast storage. Used by
     * SyncController::channelPull to read the ring buffer for a since
     * cursor; consumer plugins generally don't need to call this directly.
     */
    public function broadcastStorage(): BroadcastStorage
    {
        return $this->grav['sync_broadcast_storage'];
    }
}
