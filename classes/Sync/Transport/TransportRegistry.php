<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Transport;

use Grav\Plugin\Sync\Channel;

/**
 * Collection of registered transports. Single-request scope; rebuilt every
 * request by onSyncRegisterTransports.
 *
 * Re-registering the same transport id silently overwrites the previous
 * entry, mirroring ChannelRegistry. This is intentional for the case where
 * a deployer wants to override the bundled polling transport with a
 * vendor-customized variant from a separate plugin.
 */
final class TransportRegistry
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    public function register(TransportInterface $transport): void
    {
        $this->transports[$transport->id()] = $transport;
    }

    public function byId(string $id): ?TransportInterface
    {
        return $this->transports[$id] ?? null;
    }

    /** @return array<string, TransportInterface> */
    public function all(): array
    {
        return $this->transports;
    }

    /**
     * Transports that support the channel's message type, ordered highest
     * priority first. Availability is NOT checked here, only support; the
     * caller filters by isAvailable() depending on whether it wants
     * "could support this" (capabilities listing) or "should publish here
     * right now" (publish path).
     *
     * @return list<TransportInterface>
     */
    public function selectFor(Channel $channel): array
    {
        $type = $channel->messageType->value;
        $matches = [];
        foreach ($this->transports as $t) {
            if (in_array($type, $t->supportedMessageTypes(), true)) {
                $matches[] = $t;
            }
        }
        usort($matches, static fn (TransportInterface $a, TransportInterface $b): int =>
            $b->priority() <=> $a->priority()
        );
        return $matches;
    }
}
