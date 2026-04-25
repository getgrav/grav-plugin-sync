<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Sync\Controllers\SyncController;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\Storage\FileSyncStorage;
use Grav\Plugin\Sync\SyncStorage;
use RocketTheme\Toolbox\Event\Event;

/**
 * Sync plugin — collaboration substrate.
 *
 * Phase 1: storage + presence primitives registered as container services.
 * Phase 2: HTTP endpoints registered via grav-plugin-api's onApiRegisterRoutes
 *         hook; permissions registered via Grav's PermissionsRegisterEvent.
 */
class SyncPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000,
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized'        => [['onPluginsInitialized', 1000]],
            'onApiRegisterRoutes'         => ['onApiRegisterRoutes', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
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
                $root = rtrim(GRAV_ROOT, '/') . '/user/pages';
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
    }

    /**
     * Register our endpoints with grav-plugin-api. This event is dispatched
     * by ApiRouter::registerPluginRoutes(); $event['routes'] is an
     * ApiRouteCollector.
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
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }
}
