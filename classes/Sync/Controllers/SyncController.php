<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Controllers;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\SyncRoom;
use Grav\Plugin\Sync\SyncStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Collaboration sync endpoints.
 *
 * All endpoints live under the API plugin's configured prefix (default
 * "/api/v1/sync/..."). Binary Yjs updates are carried as base64 inside
 * the JSON envelope.
 *
 * Authentication and permissions inherit from AbstractApiController:
 *   - api.collab.read   — pull + presence
 *   - api.collab.write  — push + presence heartbeat with writes
 *   - api.pages.read    — also required; sync is gated by normal page ACL
 *   - api.pages.write   — also required for push
 */
class SyncController extends AbstractApiController
{
    private const PERMISSION_READ  = 'api.collab.read';
    private const PERMISSION_WRITE = 'api.collab.write';

    // ------------------------------------------------------------------
    // GET /sync/capabilities
    // ------------------------------------------------------------------

    public function capabilities(ServerRequestInterface $request): ResponseInterface
    {
        // Accessible to any authenticated API user; no collab permission
        // required to discover whether collab is available.
        $this->getUser($request);

        $idle = (int)$this->config->get('plugins.sync.polling.idle_interval_ms', 4000);
        $active = (int)$this->config->get('plugins.sync.polling.active_interval_ms', 1000);

        $caps = [
            'transports' => ['polling'],
            'preferred' => 'polling',
            'polling' => [
                'idle_interval_ms' => $idle,
                'active_interval_ms' => $active,
            ],
            'presence' => [
                'ttl_seconds' => (int)$this->config->get('plugins.sync.presence.ttl_seconds', 30),
            ],
        ];

        // Let transport plugins (e.g. grav-plugin-sync-mercure) enrich
        // the capabilities response with their own advertisement data.
        // The event payload is passed by reference so subscribers can
        // mutate it directly.
        $event = new \RocketTheme\Toolbox\Event\Event(['capabilities' => $caps]);
        $this->grav->fireEvent('onSyncCapabilities', $event);
        $caps = $event['capabilities'] ?? $caps;

        return ApiResponse::create($caps);
    }

    // ------------------------------------------------------------------
    // POST /sync/pages/{route:.+}/pull
    // ------------------------------------------------------------------

    public function pull(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->requirePermission($request, 'api.pages.read');

        $body = $this->getRequestBody($request);
        $room = $this->resolveRoom($request, $body);
        $since = max(0, (int)($body['since'] ?? 0));

        $storage = $this->storage();
        $res = $storage->getUpdatesSince($room->id, $since);

        $updates = array_map('base64_encode', $res['updates']);
        $peers = $this->presenceStore()->peers($room->id);

        return ApiResponse::create([
            'updates' => $updates,
            'offset' => $res['offset'],
            'size' => $res['size'],
            'peers' => $peers,
            'room' => $room->id,
            'serverTimeMs' => (int)(microtime(true) * 1000),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /sync/pages/{route:.+}/push
    // ------------------------------------------------------------------

    public function push(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->requirePermission($request, 'api.pages.write');

        $body = $this->getRequestBody($request);
        $room = $this->resolveRoom($request, $body);
        $this->requireFields($body, ['update']);

        $update = base64_decode((string)$body['update'], true);
        if ($update === false || $update === '') {
            throw new ValidationException('`update` must be non-empty base64-encoded bytes.');
        }

        $clientId = isset($body['clientId']) ? (string)$body['clientId'] : null;
        $size = $this->storage()->appendUpdate($room->id, $update, $clientId);

        // The event carries the raw update bytes so transports like
        // grav-plugin-sync-mercure can republish to their hub on the side.
        // Subscribers that only care about activity counts can read
        // `updateBytes` (the size).
        $this->grav->fireEvent('onSyncUpdate', new \RocketTheme\Toolbox\Event\Event([
            'room' => $room,
            'clientId' => $clientId,
            'update' => $update,
            'updateBytes' => strlen($update),
        ]));

        return ApiResponse::create([
            'ok' => true,
            'offset' => $size,
            'bytes' => strlen($update),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /sync/pages/{route:.+}/presence
    // ------------------------------------------------------------------

    public function presence(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->requirePermission($request, 'api.pages.read');

        $body = $this->getRequestBody($request);
        $room = $this->resolveRoom($request, $body);
        $presence = $this->presenceStore();

        $clientId = isset($body['clientId']) ? (string)$body['clientId'] : null;
        $leave = (bool)($body['leave'] ?? false);

        if ($clientId !== null && $clientId !== '') {
            if ($leave) {
                $presence->leave($room->id, $clientId);
            } else {
                $user = $this->getUser($request);
                $userName = (string)($user->get('fullname') ?? $user->username ?? $clientId);
                $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
                $presence->heartbeat($room->id, $clientId, $userName, $meta);

                // Fire an event so transports like sync-mercure can
                // republish awareness deltas on a low-latency channel.
                // Polling peers still receive awareness through the
                // response body — this is purely an additional fast path.
                if (isset($meta['awarenessUpdate']) && is_string($meta['awarenessUpdate']) && $meta['awarenessUpdate'] !== '') {
                    $this->grav->fireEvent('onSyncAwareness', new \RocketTheme\Toolbox\Event\Event([
                        'room' => $room,
                        'clientId' => $clientId,
                        'awarenessUpdateB64' => $meta['awarenessUpdate'],
                    ]));
                }
            }
        }

        return ApiResponse::create([
            'peers' => $presence->peers($room->id),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Resolve the room referenced by the request. Validates the page
     * actually exists under the given route; rejects unknown pages with
     * 404 to avoid creating storage for bogus routes.
     */
    private function resolveRoom(ServerRequestInterface $request, array $body): SyncRoom
    {
        $route = $this->getRouteParam($request, 'route');
        if (!$route) {
            throw new ValidationException('Route required.');
        }
        $route = '/' . ltrim((string)$route, '/');

        $this->enablePages();
        $page = $this->grav['pages']->find($route);
        if (!$page) {
            throw new NotFoundException("Page not found at route: {$route}");
        }

        $lang = isset($body['lang']) ? (string)$body['lang'] : null;
        $template = $page->template() ?: 'default';

        return $this->rooms()->roomFor($route, $lang ?: null, $template);
    }

    private function enablePages(): void
    {
        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $this->grav['pages'];
        $pages->enablePages();
    }

    private function storage(): SyncStorage
    {
        return $this->grav['sync_storage'];
    }

    private function presenceStore(): PresenceStore
    {
        return $this->grav['sync_presence'];
    }

    private function rooms(): RoomRegistry
    {
        return $this->grav['sync_rooms'];
    }
}
