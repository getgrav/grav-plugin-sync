<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Sync\ChannelRegistry;
use Grav\Plugin\Sync\Controllers\SyncController;
use Grav\Plugin\Sync\Http\SyncLegacyRouter;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\Storage\BroadcastStorage;
use Grav\Plugin\Sync\Storage\FileBroadcastStorage;
use Grav\Plugin\Sync\Storage\FileSyncStorage;
use Grav\Plugin\Sync\Sync;
use Grav\Plugin\Sync\SyncStorage;
use Grav\Plugin\Sync\Transport\PollingTransport;
use Grav\Plugin\Sync\Transport\TransportRegistry;
use RocketTheme\Toolbox\Event\Event;

/**
 * Sync plugin - collaboration substrate.
 *
 * Phase 1: storage + presence primitives registered as container services.
 * Phase 2: HTTP endpoints registered via two routes that produce identical
 *          behavior:
 *
 *   - When the api plugin (>= 1.0.0-beta.13) is installed, sync subscribes
 *     to its `onApiRegisterRoutes` event and surfaces its endpoints under
 *     the configured api prefix (default `/api/v1/sync/...`). Auth, rate
 *     limiting, CORS, and error mapping all flow through the api plugin's
 *     middleware exactly as before.
 *
 *   - When the api plugin is NOT installed (e.g. Grav 1.7, where the api
 *     plugin can't run), sync subscribes to `onPageInitialized` and
 *     dispatches matching `/sync/*` requests itself via SyncLegacyRouter.
 *     The legacy dispatcher resolves the user from the active session,
 *     decodes the JSON body, and routes into the same SyncController.
 *
 * The two paths are mutually exclusive: only one of `onApiRegisterRoutes`
 * or `onPageInitialized` is wired up per process. SyncController is
 * unaware of which path served the request.
 *
 * Phase 3: pub/sub generalisation. The CRDT pipeline above is unchanged
 * and remains the only path editor-pro uses. Layered on top:
 *
 *   - $grav['sync']                  - public consumer-facing facade
 *   - $grav['sync_transports']       - registry of TransportInterface impls
 *   - $grav['sync_broadcast_storage']- TTL ring buffer for broadcast channels
 *
 * Two new boot events fire after services are wired:
 *
 *   - onSyncRegisterTransports - sync core's polling transport registers
 *                                here; external plugins (sync-mercure,
 *                                sync-ably) plug in here too.
 *   - onSyncRegisterChannels   - consumer plugins register the channels
 *                                they own here.
 */
class SyncPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000,
    ];

    public static function getSubscribedEvents(): array
    {
        // The HTTP entry-path subscription (onApiRegisterRoutes vs
        // onPageInitialized) is added at runtime in onPluginsInitialized
        // based on whether the api plugin is loaded. See that method for
        // the rationale.
        return [
            'onPluginsInitialized'        => [['onPluginsInitialized', 1000]],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            // Sync core's polling transport registers itself in the same
            // event consumer plugins use; priority 100 keeps it ahead of
            // user code so transports list ordering is deterministic
            // when introspected during the same boot.
            'onSyncRegisterTransports'    => [['onRegisterCorePollingTransport', 100]],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized(): void
    {
        if (!$this->config->get('plugins.sync.enabled')) {
            return;
        }

        $this->grav['sync_storage'] = function (): SyncStorage {
            $adapter = $this->config->get('plugins.sync.storage.adapter', 'file');
            if ($adapter === 'file') {
                // Sync data lives outside user/pages so room storage never
                // shows up as extra "pages" in admin. Routes are hashed
                // before they hit the filesystem, so language/numeric-prefix
                // mismatches with the actual page folder layout don't
                // matter.
                $root = rtrim(GRAV_ROOT, '/') . '/user/data/sync';
                return new FileSyncStorage($root);
            }
            throw new \RuntimeException("sync: unsupported storage adapter '{$adapter}'");
        };

        $this->grav['sync_presence'] = function (): PresenceStore {
            /** @var \Grav\Common\Cache $cache */
            $cache = $this->grav['cache'];
            $ttl = (int)$this->config->get('plugins.sync.presence.ttl_seconds', 30);
            return new PresenceStore($cache, $ttl);
        };

        $this->grav['sync_rooms'] = function (): RoomRegistry {
            return new RoomRegistry();
        };

        $this->grav['sync_broadcast_storage'] = function (): BroadcastStorage {
            $root = rtrim(GRAV_ROOT, '/') . '/user/data/sync/broadcast';
            return new FileBroadcastStorage($root);
        };

        $this->grav['sync_channels'] = function (): ChannelRegistry {
            return new ChannelRegistry();
        };

        $this->grav['sync_transports'] = function (): TransportRegistry {
            return new TransportRegistry();
        };

        $this->grav['sync'] = function (): Sync {
            return new Sync(
                $this->grav,
                $this->grav['sync_channels'],
                $this->grav['sync_transports']
            );
        };

        // Fire the registration events. Transports first so the channel
        // facade has a populated transport registry by the time consumer
        // plugins start wiring channels (some may want to peek at
        // available transports during registration).
        $this->grav->fireEvent('onSyncRegisterTransports', new Event([
            'transports' => $this->grav['sync_transports'],
            'sync' => $this->grav['sync'],
        ]));
        $this->grav->fireEvent('onSyncRegisterChannels', new Event([
            'sync' => $this->grav['sync'],
            'channels' => $this->grav['sync_channels'],
        ]));

        // Wire the right HTTP entry path. We can't decide this in the
        // static getSubscribedEvents() because the api plugin may load
        // after static dispatch is built. enable() registers the handler
        // dynamically against the live event dispatcher.
        if (\class_exists(\Grav\Plugin\Api\ApiRouteCollector::class)) {
            $this->enable([
                'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            ]);
        } else {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
            ]);
        }
    }

    /**
     * Register sync's built-in polling transport. Always available;
     * priority 0 means it serves as the universal floor that any push
     * transport can outbid.
     */
    public function onRegisterCorePollingTransport(Event $event): void
    {
        /** @var TransportRegistry $registry */
        $registry = $event['transports'];
        $registry->register(new PollingTransport($this->grav));
    }

    /**
     * Register our endpoints with grav-plugin-api. This event is dispatched
     * by ApiRouter::registerPluginRoutes(); $event['routes'] is an
     * ApiRouteCollector. Only wired up when the api plugin is loaded.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.sync.enabled')) {
            return;
        }

        /** @var \Grav\Plugin\Api\ApiRouteCollector $routes */
        $routes = $event['routes'];

        $routes->get('/sync/capabilities', [SyncController::class, 'capabilities']);

        $routes->group('/sync/pages/{route:.+}', function ($r): void {
            $r->post('/pull',     [SyncController::class, 'pull']);
            $r->post('/push',     [SyncController::class, 'push']);
            $r->post('/init',     [SyncController::class, 'init']);
            $r->post('/presence', [SyncController::class, 'presence']);
        });

        // Channel-scoped pub/sub endpoints. Channel ids may include
        // colons, slashes, and @-signs; the {id:.+} pattern absorbs the
        // remainder of the URL so the api router doesn't try to split it.
        $routes->group('/sync/channels/{id:.+}', function ($r): void {
            $r->get('/pull',     [SyncController::class, 'channelPull']);
            $r->post('/publish', [SyncController::class, 'channelPublish']);
        });
    }

    /**
     * Legacy HTTP dispatcher for environments without the api plugin.
     * Matches `/sync/*` paths and hands off to SyncLegacyRouter, which
     * builds a PSR-7 request from the globals and invokes the same
     * SyncController actions the api path uses.
     */
    public function onPageInitialized(): void
    {
        if (!$this->config->get('plugins.sync.enabled')) {
            return;
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $path = $uri->path();
        if (!is_string($path) || !str_starts_with($path, '/sync/')) {
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        (new SyncLegacyRouter($this->grav))->tryHandle($path, $method);
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }
}
