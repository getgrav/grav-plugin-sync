<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\Storage\FileSyncStorage;
use Grav\Plugin\Sync\SyncStorage;

/**
 * Sync plugin — collaboration substrate.
 *
 * Phase 1: storage + presence primitives registered as container services.
 * Route registration lives in grav-plugin-api (Phase 2).
 */
class SyncPlugin extends Plugin
{
    public $features = [
        'blueprints' => 1000,
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['onPluginsInitialized', 1000]],
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
}
