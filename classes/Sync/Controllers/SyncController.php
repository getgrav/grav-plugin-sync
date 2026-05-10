<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Controllers;

use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Http\AbstractSyncController;
use Grav\Plugin\Sync\Http\Exceptions\ForbiddenException;
use Grav\Plugin\Sync\Http\Exceptions\NotFoundException;
use Grav\Plugin\Sync\Http\Exceptions\ValidationException;
use Grav\Plugin\Sync\Http\SyncResponse;
use Grav\Plugin\Sync\Message\BroadcastMessage;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\Sync\PresenceStore;
use Grav\Plugin\Sync\RoomRegistry;
use Grav\Plugin\Sync\Sync;
use Grav\Plugin\Sync\SyncRoom;
use Grav\Plugin\Sync\SyncStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Collaboration sync endpoints.
 *
 * Reachable at "/api/v1/sync/..." when the api plugin is installed (it
 * dispatches into these methods via onApiRegisterRoutes), and at
 * "/sync/..." via the legacy dispatcher in sync.php when running on
 * Grav 1.7 or any setup without the api plugin. Binary Yjs updates are
 * carried as base64 inside the JSON envelope.
 *
 * Authentication and permissions inherit from AbstractSyncController:
 *   - api.collab.read   - pull + presence
 *   - api.collab.write  - push + presence heartbeat with writes
 *   - api.pages.read    - also required; sync is gated by normal page ACL
 *   - api.pages.write   - also required for push
 *
 * Round 3 adds the channel-scoped endpoints below for the broadcast
 * pub/sub model. Page-scoped CRDT endpoints are unchanged on the wire.
 */
class SyncController extends AbstractSyncController
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

        // Enumerate registered transports for the capabilities response.
        // Highest priority wins the `preferred` slot; if nothing is
        // registered (shouldn't happen because polling is always
        // registered) we fall back to 'polling' as a safe default.
        $sync = $this->sync();
        $transportList = [];
        $preferred = 'polling';
        $bestPriority = PHP_INT_MIN;
        foreach ($sync->transports() as $t) {
            if (!$t->isAvailable()) {
                continue;
            }
            $transportList[] = [
                'id' => $t->id(),
                'name' => $t->name(),
                'priority' => $t->priority(),
                'supports' => $t->supportedMessageTypes(),
            ];
            if ($t->priority() > $bestPriority) {
                $bestPriority = $t->priority();
                $preferred = $t->id();
            }
        }

        $caps = [
            'transports' => $transportList,
            'preferred' => $preferred,
            // The polling and presence sub-blocks are byte-identical to
            // their pre-Round-3 form. Editor-pro's existing parser reads
            // them directly; do not change shape, key order, or types.
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

        return SyncResponse::create($caps);
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
        $this->ensureCrdtChannel($room);
        $since = max(0, (int)($body['since'] ?? 0));

        $storage = $this->storage();
        $res = $storage->getUpdatesSince($room->id, $since);

        $updates = array_map('base64_encode', $res['updates']);
        $peers = $this->presenceStore()->peers($room->id);

        return SyncResponse::create([
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
        $this->ensureCrdtChannel($room);
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

        return SyncResponse::create([
            'ok' => true,
            'offset' => $size,
            'bytes' => strlen($update),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /sync/pages/{route:.+}/init
    // ------------------------------------------------------------------

    /**
     * Atomically seed the room iff its log is empty. Resolves the empty-
     * room race where two clients opening a fresh page would otherwise
     * both push their seed and double-apply initial state.
     *
     * Winners get `{seeded: true}`; losers get `{seeded: false, updates}`
     * carrying the canonical state in one round trip so they don't have
     * to wait for the next pull tick to learn what the winner wrote.
     */
    public function init(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->requirePermission($request, 'api.pages.write');

        $body = $this->getRequestBody($request);
        $room = $this->resolveRoom($request, $body);
        $this->ensureCrdtChannel($room);
        $this->requireFields($body, ['seed']);

        $seed = base64_decode((string)$body['seed'], true);
        if ($seed === false || $seed === '') {
            throw new ValidationException('`seed` must be non-empty base64-encoded bytes.');
        }

        $clientId = isset($body['clientId']) ? (string)$body['clientId'] : null;
        $storage = $this->storage();
        $result = $storage->initIfEmpty($room->id, $seed);

        $response = [
            'seeded' => $result['seeded'],
            'offset' => $result['size'],
            'size' => $result['size'],
            'room' => $room->id,
        ];

        if ($result['seeded']) {
            // Notify transport plugins so peers learn about the seed.
            // sync-mercure republishes onto the SSE channel so any peer
            // subscribed before the loser-path pull also picks it up.
            $this->grav->fireEvent('onSyncUpdate', new \RocketTheme\Toolbox\Event\Event([
                'room' => $room,
                'clientId' => $clientId,
                'update' => $seed,
                'updateBytes' => strlen($seed),
            ]));
        } else {
            // Loser path — return the current log inline so the caller
            // can adopt the winner's state without an extra pull.
            $res = $storage->getUpdatesSince($room->id, 0);
            $response['offset'] = $res['offset'];
            $response['updates'] = array_map('base64_encode', $res['updates']);
        }

        return SyncResponse::create($response);
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

        return SyncResponse::create([
            'peers' => $presence->peers($room->id),
        ]);
    }

    // ------------------------------------------------------------------
    // GET /sync/channels/{id}/pull?since={ts}
    // ------------------------------------------------------------------

    /**
     * Read broadcast messages for a channel since the given timestamp.
     *
     * Auth: must be authenticated, AND Sync::checkAccess($channelId,
     * $user, 'subscribe') must return true. The api.collab.read +
     * api.pages.read pair on the page-scoped endpoints isn't appropriate
     * here, because channels aren't necessarily page-scoped; per-channel
     * auth is delegated to the owner plugin via checkAccess.
     */
    public function channelPull(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getUser($request);
        $channelId = $this->requireChannelId($request);
        $channel = $this->requireChannel($channelId);

        if (!$this->sync()->checkAccess($channelId, $user, 'subscribe')) {
            throw new ForbiddenException("Not authorized to subscribe to channel '{$channelId}'.");
        }

        $params = $request->getQueryParams();
        $since = isset($params['since']) ? (int)$params['since'] : null;

        $messages = [];
        if ($channel->messageType === MessageType::Broadcast) {
            $messages = $this->sync()->broadcastStorage()->since($channelId, $since);
        }

        return SyncResponse::create([
            'channel' => $channel->id,
            'messageType' => $channel->messageType->value,
            'messages' => $messages,
            'serverTimeMs' => (int)(microtime(true) * 1000),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /sync/channels/{id}/publish
    // ------------------------------------------------------------------

    /**
     * Publish a broadcast or awareness message into a channel from the
     * client side. Most consumer plugins publish from PHP via
     * Sync::publish() and won't expose this endpoint in their UI; it
     * exists so client-driven flows (typing indicators, ad-hoc
     * comments-pro emoji reactions, etc.) can broadcast without round-
     * tripping through a plugin-specific REST endpoint.
     *
     * Auth: Sync::checkAccess($channelId, $user, 'publish') must allow.
     * Crdt channels are intentionally rejected here — CRDT writes go
     * through /sync/pages/{route}/push so they hit the canonical room
     * storage with the same wire format editor-pro relies on.
     */
    public function channelPublish(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getUser($request);
        $channelId = $this->requireChannelId($request);
        $channel = $this->requireChannel($channelId);

        if ($channel->messageType === MessageType::Crdt) {
            throw new ValidationException(
                "Channel '{$channelId}' is a crdt channel; use /sync/pages/{route}/push for crdt writes."
            );
        }

        if (!$this->sync()->checkAccess($channelId, $user, 'publish')) {
            throw new ForbiddenException("Not authorized to publish to channel '{$channelId}'.");
        }

        $body = $this->getRequestBody($request);

        switch ($channel->messageType) {
            case MessageType::Broadcast:
                $payload = is_array($body['payload'] ?? null) ? $body['payload'] : null;
                if ($payload === null) {
                    throw new ValidationException('`payload` is required for broadcast channels.');
                }
                $eventName = isset($body['event']) ? (string)$body['event'] : null;
                $message = new BroadcastMessage($payload, $eventName);
                $this->sync()->publish($channelId, $message);
                return SyncResponse::create([
                    'ok' => true,
                    'channel' => $channel->id,
                    'timestamp' => $message->timestamp,
                ]);

            case MessageType::Awareness:
                $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
                $sourceClientId = isset($body['clientId']) ? (string)$body['clientId'] : '';
                if ($sourceClientId === '') {
                    throw new ValidationException('`clientId` is required for awareness channels.');
                }
                $message = new \Grav\Plugin\Sync\Message\AwarenessMessage($payload, $sourceClientId);
                $this->sync()->publish($channelId, $message);
                return SyncResponse::create([
                    'ok' => true,
                    'channel' => $channel->id,
                ]);

            default:
                throw new ValidationException("Channel '{$channelId}' has unsupported message type for publish endpoint.");
        }
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

    /**
     * Lazy-register a CRDT channel for a room when an existing CRDT
     * endpoint touches it. Backward-compat: editor-pro never explicitly
     * registers channels, but Round 3 wants every active room visible
     * through the channel registry for diagnostics + future transports.
     *
     * The auth callback for these auto-registered channels is null, so
     * any subscribe/publish access check would fall through to the
     * onSyncCheckAccess event. CRDT writes don't go through that path
     * (they use the page-scoped /pull /push /init endpoints with their
     * own permission gating) so the lack of an auth callback here is
     * not a privilege escalation.
     */
    private function ensureCrdtChannel(SyncRoom $room): void
    {
        $sync = $this->sync();
        $channelId = 'editor-pro:' . $room->id;
        if ($sync->getChannel($channelId) !== null) {
            return;
        }
        $sync->registerChannel(new Channel(
            id: $channelId,
            ownerPlugin: 'editor-pro',
            messageType: MessageType::Crdt,
            authCallback: null,
            metadata: [
                'room' => $room->id,
                'route' => $room->route,
                'template' => $room->template,
                'language' => $room->language,
                'autoRegistered' => true,
            ],
        ));
    }

    private function requireChannelId(ServerRequestInterface $request): string
    {
        $id = $this->getRouteParam($request, 'id');
        if ($id === null || $id === '') {
            throw new ValidationException('Channel id required.');
        }
        return $id;
    }

    private function requireChannel(string $channelId): Channel
    {
        $channel = $this->sync()->getChannel($channelId);
        if ($channel === null) {
            throw new NotFoundException("Channel not found: {$channelId}");
        }
        return $channel;
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

    private function sync(): Sync
    {
        return $this->grav['sync'];
    }
}
