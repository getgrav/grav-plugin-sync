<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Transport;

use Grav\Common\Grav;
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
     * Transports that support the channel's message type, ordered by the
     * admin-configured preference (plugins.sync.transport_preference). Any
     * registered transport not named in the preference is appended after
     * the named ones, sorted by its internal priority() so newly-installed
     * transports still resolve sensibly. If no preference is configured,
     * falls back to pure priority() ordering.
     *
     * Availability is NOT checked here, only support; the caller filters by
     * isAvailable() depending on whether it wants "could support this"
     * (capabilities listing) or "should publish here right now" (publish
     * path).
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

        $preference = self::normalizePreference(
            (array) Grav::instance()['config']->get('plugins.sync.transport_preference', [])
        );
        if ($preference === []) {
            usort($matches, static fn (TransportInterface $a, TransportInterface $b): int =>
                $b->priority() <=> $a->priority()
            );
            return $matches;
        }

        $rank = array_flip($preference);
        usort($matches, static function (TransportInterface $a, TransportInterface $b) use ($rank): int {
            $ra = $rank[$a->id()] ?? PHP_INT_MAX;
            $rb = $rank[$b->id()] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // Both unranked (newly installed, not yet in saved preference);
            // fall back to internal priority so they still order sensibly.
            return $b->priority() <=> $a->priority();
        });
        return $matches;
    }

    /**
     * Flat list of transport ids from the admin-saved preference. Blank
     * rows are filtered out so the admin's empty starter row doesn't
     * shadow real transports.
     *
     * @param  array<mixed> $raw
     * @return list<string>
     */
    private static function normalizePreference(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Blueprint data-options@ source for the sync plugin's
     * transport_preference field. Returns [id => name] for transports that
     * are currently registered in this request — drives the admin
     * reordering UI so deployers only see options they actually have
     * installed.
     *
     * @return array<string, string>
     */
    public static function availableTransports(): array
    {
        $grav = Grav::instance();
        if (!isset($grav['sync_transports'])) {
            return [];
        }
        /** @var TransportRegistry $registry */
        $registry = $grav['sync_transports'];
        $out = [];
        foreach ($registry->all() as $id => $transport) {
            $out[$id] = $transport->name();
        }
        return $out;
    }
}
