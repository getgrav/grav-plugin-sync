<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\ChannelRegistry;
use Grav\Plugin\Sync\Controllers\SyncController;
use Grav\Plugin\Sync\Message\BroadcastMessage;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\Sync\Http\SyncLegacyRouter;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\Storage\BroadcastStorage;
use Grav\Plugin\Sync\Storage\FileBroadcastStorage;
use Grav\Plugin\Sync\Storage\FileSyncStorage;
use Grav\Plugin\Sync\Storage\SqliteSyncStorage;
use Grav\Plugin\Sync\Sync;
use Grav\Plugin\Sync\SyncStorage;
use Grav\Plugin\Sync\Transport\PollingTransport;
use Grav\Plugin\Sync\Transport\TransportRegistry;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

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
            // Two passes on onPluginsInitialized:
            //   1000 — register container services (sync_storage, sync_channels,
            //          sync_transports, the public sync facade) and pick the
            //          HTTP entry path. Side-car plugins (sync-mercure,
            //          sync-ably) also run their onPluginsInitialized at 1000,
            //          where they register $grav['mercure'] and
            //          $grav['sync_ably'] respectively.
            //   100  — fire onSyncRegisterTransports / onSyncRegisterChannels.
            //          By this priority every plugin's 1000-tier handler has
            //          run, so side-cars' static onSyncRegisterTransports
            //          listeners can resolve their services from the container
            //          and actually register their transports. Firing these
            //          events from inside the 1000-priority pass would race
            //          alphabetical plugin order and silently leave the
            //          registry empty for any side-car that sorts after sync.
            'onPluginsInitialized'        => [
                ['onPluginsInitialized', 1000],
                ['onPluginsInitializedRegistration', 100],
            ],
            // Sync core's polling transport registers itself in the same
            // event consumer plugins use; priority 100 keeps it ahead of
            // user code so transports list ordering is deterministic
            // when introspected during the same boot.
            'onSyncRegisterTransports'    => [['onRegisterCorePollingTransport', 100]],
            // Broadcast a `page-saved` event to peers after the api plugin
            // writes a page to disk. Late priority — runs after the api
            // plugin's own listeners have settled the page state.
            'onApiPageUpdated'            => [['onApiPageUpdated', 100]],
            // Lazy-register our own `sync:page-saved:<roomId>` channels when a
            // client pulls/subscribes before the first save in this worker has
            // happened. Without this the editor 404s on its initial subscribe.
            'onSyncResolveChannel'        => [['onSyncResolveChannel', 0]],
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
            // 'auto' (the default) prefers sqlite when the pdo_sqlite
            // extension is available and falls back to the file backend
            // otherwise. Explicit 'sqlite' or 'file' overrides the choice.
            $adapter = (string)$this->config->get('plugins.sync.storage.adapter', 'auto');
            if ($adapter === 'auto') {
                $adapter = extension_loaded('pdo_sqlite') ? 'sqlite' : 'file';
            }

            $base = rtrim(GRAV_ROOT, '/') . '/user/data/sync';

            if ($adapter === 'sqlite') {
                if (!extension_loaded('pdo_sqlite')) {
                    throw new \RuntimeException(
                        "sync: storage.adapter='sqlite' but the pdo_sqlite PHP extension is not loaded"
                    );
                }
                return new SqliteSyncStorage($base . '/storage');
            }

            if ($adapter === 'file') {
                // Sync data lives outside user/pages so room storage never
                // shows up as extra "pages" in admin. Routes are hashed
                // before they hit the filesystem, so language/numeric-prefix
                // mismatches with the actual page folder layout don't
                // matter.
                return new FileSyncStorage($base);
            }

            throw new \RuntimeException("sync: unsupported storage adapter '{$adapter}'");
        };

        $this->grav['sync_presence'] = function (): PresenceStore {
            $ttl = (int)$this->config->get('plugins.sync.presence.ttl_seconds', 30);

            // Presence must NOT ride Grav's shared Cache facade: Cache::clearCache()
            // (called after every page write) touches user/config/system.yaml,
            // which bumps Config::key(), which rotates the entire cache/grav/<hash>
            // directory on the next request — silently orphaning every room's
            // presence data until each peer's next heartbeat lands in the new
            // folder. Route presence through a dedicated adapter rooted at a
            // fixed path this plugin owns, same convention as
            // FileSyncStorage/FileBroadcastStorage below.
            $dir = rtrim(GRAV_ROOT, '/') . '/user/data/sync/presence';
            $cache = new Psr16Cache(new FilesystemAdapter(namespace: '', defaultLifetime: 0, directory: $dir));

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

        // Wire the right HTTP entry path. We can't decide this in the
        // static getSubscribedEvents() because the api plugin may load
        // after static dispatch is built. enable() registers the handler
        // dynamically against the live event dispatcher.
        if (\class_exists(\Grav\Plugin\Api\ApiRouteCollector::class)) {
            $this->enable([
                'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
                'onApiCollectPublicRoutes' => ['onApiCollectPublicRoutes', 0],
            ]);
        } else {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
            ]);
        }
    }

    /**
     * Second-pass plugin init at priority 100. Runs after every plugin's
     * priority-1000 onPluginsInitialized handler, so side-car services
     * (e.g. $grav['mercure'], $grav['sync_ably']) are guaranteed to be
     * in the container before we ask transports / channel owners to
     * register themselves.
     */
    public function onPluginsInitializedRegistration(): void
    {
        if (!$this->config->get('plugins.sync.enabled')) {
            return;
        }

        // Transports first so the channel facade has a populated transport
        // registry by the time consumer plugins start wiring channels —
        // some may peek at available transports during registration.
        $this->grav->fireEvent('onSyncRegisterTransports', new Event([
            'transports' => $this->grav['sync_transports'],
            'sync' => $this->grav['sync'],
        ]));
        $this->grav->fireEvent('onSyncRegisterChannels', new Event([
            'sync' => $this->grav['sync'],
            'channels' => $this->grav['sync_channels'],
        ]));
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
     * The channel pull/publish endpoints run optimistic auth: anonymous
     * requests reach the controller as guests and every channel's own
     * authCallback (or onSyncCheckAccess, default deny) decides. Without
     * this, guests on public broadcast channels could never use the
     * polling fallback — the api auth layer 401'd them at the door.
     */
    public function onApiCollectPublicRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.sync.enabled')) {
            return;
        }

        $apiBase = (string)$event['api_base'];
        $exact = (array)($event['exact'] ?? []);
        $exact[] = 'GET ' . $apiBase . '/sync/channels/pull';
        $exact[] = 'POST ' . $apiBase . '/sync/channels/publish';
        $event['exact'] = $exact;
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

        // Channel-scoped pub/sub endpoints. The channel id rides in the
        // query string (`?id=...`) rather than as a path segment, because
        // Grav's URI parser eats `:`-bearing path segments as URI params
        // (`system.param_sep` defaults to `:`), and channel ids legitimately
        // contain colons (e.g. `comments-pro:blog/post-1`). Keeping the id
        // off the path side-steps that entirely.
        $routes->get('/sync/channels/pull',     [SyncController::class, 'channelPull']);
        $routes->post('/sync/channels/publish', [SyncController::class, 'channelPublish']);
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
        if (!is_string($path)) {
            return;
        }

        // On subpath installs (e.g. /sync-testing/grav-c) $uri->path() may
        // include the base; strip it before checking the /sync/ prefix so
        // the legacy router doesn't silently bail.
        $base = rtrim((string)$uri->rootUrl(false), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if (!str_starts_with($path, '/sync/')) {
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        (new SyncLegacyRouter($this->grav))->tryHandle($path, $method);
    }

    /**
     * Fan out a `page-saved` broadcast to peers in the same collab room
     * after the api plugin writes a page to disk. Editors subscribed to
     * the page's broadcast channel use this to advance their local
     * baseline so the unsaved-changes guard doesn't trip on edits a peer
     * already saved.
     *
     * Channel id: `sync:page-saved:<roomId>`. Lazily registered the
     * first time a save happens for the room; auth is page-read since
     * subscribers shouldn't learn about saves on pages they can't see.
     * Publish is server-side only — clients never call the publish
     * endpoint for this channel.
     */
    public function onApiPageUpdated(Event $event): void
    {
        $page = $event['page'] ?? null;
        if (!$page instanceof \Grav\Common\Page\Interfaces\PageInterface) {
            return;
        }
        if (!isset($this->grav['sync'], $this->grav['sync_rooms'])) {
            return;
        }

        /** @var Sync $sync */
        $sync = $this->grav['sync'];
        /** @var RoomRegistry $rooms */
        $rooms = $this->grav['sync_rooms'];

        try {
            $lang = $page->language() ?: null;
            $room = $rooms->roomForPage($page, $lang);
        } catch (\Throwable) {
            return; // page with no usable route — nothing to broadcast against
        }

        $channelId = 'sync:page-saved:' . $room->id;
        $this->ensurePageSavedChannel($sync, $channelId);

        $savedBy = null;
        $user = $this->grav['user'] ?? null;
        if ($user && (bool) ($user->authenticated ?? false)) {
            $savedBy = [
                'username' => (string) ($user->username ?? ''),
                'fullname' => (string) ($user->fullname ?? ''),
            ];
        }

        $payload = [
            'kind' => 'page-saved',
            'roomId' => $room->id,
            'route' => $room->route,
            'template' => $room->template,
            'language' => $room->language,
            'savedAt' => (int) (microtime(true) * 1000),
            'savedBy' => $savedBy,
        ];

        try {
            $sync->publish($channelId, new BroadcastMessage($payload, 'page-saved'));
        } catch (\Throwable) {
            // Sync transport not yet ready, or storage unavailable.
            // Don't let a notification failure abort the save flow.
        }
    }

    /**
     * Lazy-registration hook from SyncController::requireChannel. A pull or
     * subscribe request can name a `sync:page-saved:<roomId>` channel before
     * any save has happened in this worker, so the channel won't be in the
     * in-process registry yet. Register it on demand so the request is served
     * (with an empty/whatever-is-buffered broadcast) instead of 404ing.
     */
    public function onSyncResolveChannel(Event $event): void
    {
        $channelId = (string)($event['channelId'] ?? '');
        if (!str_starts_with($channelId, 'sync:page-saved:')) {
            return;
        }
        if (!isset($this->grav['sync'], $this->grav['sync_rooms'])) {
            return;
        }

        // Validate the room id encoded in the channel id before registering,
        // so a malformed/hostile id doesn't mint a junk channel.
        $roomId = substr($channelId, strlen('sync:page-saved:'));
        try {
            $this->grav['sync_rooms']->parse($roomId);
        } catch (\Throwable) {
            return;
        }

        $this->ensurePageSavedChannel($this->grav['sync'], $channelId);
    }

    /**
     * Register the `sync:page-saved:<roomId>` broadcast channel if it isn't
     * already known to this worker. Shared by the save broadcaster and the
     * lazy-resolution hook so both paths register identical auth + TTL.
     */
    private function ensurePageSavedChannel(Sync $sync, string $channelId): void
    {
        if ($sync->getChannel($channelId) !== null) {
            return;
        }
        $grav = $this->grav;
        $sync->registerChannel(new Channel(
            id: $channelId,
            ownerPlugin: 'sync',
            messageType: MessageType::Broadcast,
            // Subscribe is the only client-facing action — gated on
            // page-read so subscribers can't learn about saves on
            // pages they couldn't otherwise see. Publish is
            // server-side only; reject any client publish attempt.
            authCallback: static fn ($user, string $action): bool =>
                $user !== null
                && $action === 'subscribe'
                && self::userCanReadPages($grav, $user),
            broadcastTtlSeconds: 60,
            broadcastMaxMessages: 10,
        ));
    }

    /**
     * Resolve `api.pages.read` for a user the same way the HTTP endpoints do
     * (AbstractSyncController::requirePermission): super-admin bypass first,
     * then the api plugin's PermissionResolver so group/role inheritance and
     * the `api.access` gate are honored, falling back to a raw access lookup
     * when the api plugin isn't loaded. A raw `$user->get('access.api.pages.read')`
     * alone would 403 super admins, who hold `access.api.super` but no explicit
     * pages.read grant.
     */
    private static function userCanReadPages($grav, $user): bool
    {
        if ((bool) $user->get('access.api.super')) {
            return true;
        }

        if (\class_exists(\Grav\Plugin\Api\PermissionResolver::class, true)
            && isset($grav['permissions'])) {
            $resolver = new \Grav\Plugin\Api\PermissionResolver($grav['permissions']);
            return $resolver->resolve($user, 'api.access')
                && $resolver->resolve($user, 'api.pages.read');
        }

        return (bool) $user->get('access.api.pages.read');
    }

}
