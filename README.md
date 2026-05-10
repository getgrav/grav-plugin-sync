# Grav Sync Plugin

Real-time pub/sub substrate for [Grav CMS](https://getgrav.org) 1.7 and 2.0.

Sync started life as the server side of multi-user editing in admin-next: it stored [Yjs](https://yjs.dev) CRDT updates and presence state for any page, then squashed them back into the canonical Markdown file when collaborators left. It now generalizes that model into a pluggable pub/sub substrate that any Grav plugin can consume. Sync hosts three message types (CRDT, broadcast, awareness), routes them through a transport registry (built-in HTTP polling, optional Mercure SSE, optional Ably, plus anything a third-party plugin registers), and exposes a single facade at `$grav['sync']` so consumers never bind to a specific transport.

The original CRDT path that editor-pro relies on is unchanged. All existing endpoints, events, and on-disk layouts remain compatible.

## What it does

- **CRDT storage.** Append-only log of opaque Yjs update bytes per page, with optional squashed snapshots. The PHP server does no CRDT decoding; merging happens in the browser.
- **Broadcast channels.** Arbitrary JSON payloads with optional TTL ring-buffer replay for late joiners. Useful for plugin lifecycle events (comments-pro, notifications) where ordering is best-effort.
- **Awareness channels.** Fully ephemeral presence/cursor/typing payloads. Never stored, never replayed.
- **Transport registry.** Polling is always available; companion plugins (`sync-mercure`, `sync-ably`, custom) register additional transports with their own priorities.
- **Self-contained HTTP layer.** When the api plugin is present, sync mounts its endpoints at `/api/v1/sync/*` and inherits api's full auth chain. When api is absent (e.g. Grav 1.7), sync provides its own legacy `/sync/*` dispatcher.
- **Race-free init / squash to source.** Atomic empty-room seed, idle-driven squash back to the underlying `*.md` file, then log truncation.

## Requirements

- Grav CMS 1.7.49+ or 2.0+
- PHP 8.3+
- Login plugin `>= 3.8.0`
- API plugin `>= 1.0.0-beta.13` (optional; enables the `/api/v1/sync/*` prefix and api's full auth chain)

## Installation

### GPM (preferred)

```bash
bin/grav install sync
```

### Manual

1. Clone or download this repository into `user/plugins/sync`.
2. Enable the plugin in Admin or via `user/config/plugins/sync.yaml`.

The plugin ships with `vendor/` pre-installed, so no `composer install` step is required at the user end. Maintainers updating dependencies should commit the resulting `vendor/` after running `composer install --no-dev`.

## Architecture overview

```
Consumer plugins (comments-pro, editor-pro, notifications, ...)
              |
              | $grav['sync']->publish($channelId, $message)
              v
         +-----------+
         |   Sync    |  Channel registry, auth delegation, transport selection
         +-----------+
              |
              | $transport->publish($channel, $message)
              v
  +--------------------------------------------------+
  |   Polling   |   Mercure (SSE)   |   Ably   | ... |   Transport providers
  +--------------------------------------------------+
              |
              | delivers via SSE / cloud pubsub / next poll
              v
   Browser clients (window.SyncMercure, window.SyncAbly, polling fetch)
```

The facade owns no transports; every push backend (and the built-in polling backend) registers itself via `onSyncRegisterTransports`. Channel selection picks the highest-priority available transport whose `supportedMessageTypes()` includes the channel's `MessageType`.

## Message types

Picked at channel-registration time; never changes for that channel's lifetime.

| Type | Storage | Replay on subscribe | Order | Typical use |
|------|---------|---------------------|-------|-------------|
| `crdt` | Append-only log of opaque binary updates | Full log from cursor | CRDT-defined (commutative) | editor-pro document collaboration |
| `broadcast` | TTL ring buffer (per-channel TTL + max messages) | Entries newer than the supplied `since` cursor | Best-effort temporal | comments-pro lifecycle, notifications |
| `awareness` | None | None | Best-effort temporal, listener-only | Typing indicators, cursor positions, presence |

Broadcast TTL and ring-buffer cap are per-channel, set on the `Channel` value object; setting either to 0 disables storage entirely (the channel becomes effectively awareness-shaped).

## Channels

A channel is the registered pub/sub target. Channel ids follow the convention `<owner-plugin>:<scope>`, for example:

- `editor-pro:blog/post-1@default`
- `comments-pro:blog/post-1`
- `notifications:user-42`

Sync treats the id as an opaque key, it does not enforce a specific scope format.

### Constructor

```php
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\MessageType;

$channel = new Channel(
    id: 'comments-pro:blog/post-1',
    ownerPlugin: 'comments-pro',
    messageType: MessageType::Broadcast,
    authCallback: function ($user, $action) { /* ... */ return true; },
    broadcastTtlSeconds: 60,
    broadcastMaxMessages: 50,
    metadata: ['route' => 'blog/post-1'],
);
```

### Eager vs lazy registration

- **Eager.** Register every channel at boot in `onSyncRegisterChannels`. Fine when the channel set is small and known.
- **Lazy (preferred for plugins owning many channels).** Register on first reference, e.g. inside the controller that builds the client config or handles a publish, then look up via `$sync->getChannel($id)`. This avoids walking every page or every entity at boot.

### Auth delegation

Either:

- pass `authCallback` to the `Channel` constructor, sync calls it with `($user, $action)` where `$action` is conventionally `'subscribe'` or `'publish'`; or
- omit `authCallback` and listen for `onSyncCheckAccess` on the global event bus. Inspect `$event['channel_id']` and flip `$event['allowed'] = true` to grant.

Default-deny if neither path resolves to true.

## Public API quick reference

The facade lives at `$grav['sync']` and exposes:

| Method | Purpose |
|--------|---------|
| `registerChannel(Channel $c): void` | Add a channel to the registry. |
| `getChannel(string $id): ?Channel` | Look up a registered channel. |
| `allChannels(): array` | Enumerate every registered channel. |
| `publish(string $channelId, Message $msg): void` | Validate + fan out to every available transport that supports the channel's type. |
| `clientConfigFor(string $channelId, ?UserInterface $user): array` | Build the merged JS-client config (active transport id + per-transport configs). |
| `checkAccess(string $channelId, ?UserInterface $user, string $action): bool` | Run the channel's auth callback or fire `onSyncCheckAccess`. |
| `transports(): array` | All registered transports keyed by id. |
| `activeTransportFor(Channel $channel): ?TransportInterface` | Highest-priority available transport for the channel. |

### Example: consumer-plugin publish flow with lazy registration

```php
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\MessageType;
use Grav\Plugin\Sync\Message\BroadcastMessage;

$grav = Grav::instance();
$sync = $grav['sync'];

$channelId = "comments-pro:{$pageRoute}";

if ($sync->getChannel($channelId) === null) {
    $sync->registerChannel(new Channel(
        id: $channelId,
        ownerPlugin: 'comments-pro',
        messageType: MessageType::Broadcast,
        authCallback: fn($user) => $user !== null,
    ));
}

$sync->publish($channelId, new BroadcastMessage(
    event: 'comment.created',
    payload: ['id' => $commentId, 'author' => $user->username],
));
```

### Example: consumer-plugin client-config endpoint

The consumer plugin owns its own endpoint that gates access and forwards the merged config to the browser:

```php
public function getClientConfig(string $route): array
{
    $grav = Grav::instance();
    $sync = $grav['sync'];
    $user = $grav['user'] ?? null;

    $channelId = "comments-pro:{$route}";
    if (!$sync->checkAccess($channelId, $user, 'subscribe')) {
        throw new \RuntimeException('forbidden');
    }

    return $sync->clientConfigFor($channelId, $user);
}
```

The browser receives `{ channel, messageType, active, transports: { polling: {...}, mercure: {...} } }` and picks the active transport's config.

## Transport providers

Transports register themselves by listening to `onSyncRegisterTransports` and calling `$grav['sync_transports']->register($this)`. Selection picks the highest-priority *available* transport whose `supportedMessageTypes()` includes the channel's `MessageType`.

| Transport | Source | Notes |
|-----------|--------|-------|
| `polling` | Built-in (priority 0) | Always available. No external service. Endpoint at `/sync/channels/{id}/pull` (or `/api/v1/sync/channels/{id}/pull` when api is loaded). Owns broadcast persistence. |
| `mercure` | [grav-plugin-sync-mercure](https://github.com/getgrav/grav-plugin-sync-mercure) | Optional. Adds SSE push via a Mercure hub. |
| `ably` | grav-plugin-sync-ably | Optional. Adds Ably cloud pub/sub. |
| Custom | Your plugin | Implement `TransportInterface`, register on `onSyncRegisterTransports`. |

### Custom transport skeleton

```php
use Grav\Plugin\Sync\Channel;
use Grav\Plugin\Sync\Message\Message;
use Grav\Plugin\Sync\Transport\TransportInterface;

final class MyTransport implements TransportInterface
{
    public function id(): string { return 'mything'; }
    public function name(): string { return 'My Thing'; }
    public function isAvailable(): bool { return true; }
    public function supportedMessageTypes(): array { return ['broadcast', 'awareness']; }
    public function priority(): int { return 60; }

    public function publish(Channel $channel, Message $message): void
    {
        // hand off to your push backend
    }

    public function clientConfig(Channel $channel, ?UserInterface $user): array
    {
        return ['endpoint' => '...', 'token' => '...'];
    }
}
```

Register via:

```php
public function onSyncRegisterTransports(Event $event): void
{
    $event['transports']->register(new MyTransport());
}
```

## HTTP endpoints

When the api plugin is loaded, every endpoint below is also served under the api prefix and uses api's full auth chain (X-API-Token, Authorization Bearer, session). When api is absent, sync's legacy dispatcher serves the `/sync/*` paths using session auth (augmented with api's auth chain when both plugins happen to be loaded together, since 1.1.1).

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/sync/capabilities` | Discover transports, polling defaults, presence TTL. |
| `POST` | `/sync/pages/{route}/init` | Atomically seed an empty CRDT room. |
| `POST` | `/sync/pages/{route}/pull` | Fetch CRDT updates since an opaque cursor. |
| `POST` | `/sync/pages/{route}/push` | Append a binary CRDT update to the room's log. |
| `POST` | `/sync/pages/{route}/presence` | Awareness heartbeat / leave. |
| `GET`  | `/sync/channels/{id}/pull?since={ts}` | Broadcast pull for a registered channel. |
| `POST` | `/sync/channels/{id}/publish` | Server-side broadcast publish (rare; most plugins publish via the facade). |

All paths above also work under `/api/v1/` when the api plugin is loaded. The two prefixes are mutually exclusive per process: sync wires up exactly one HTTP entry path (api router or legacy dispatcher) at boot.

`{route}` is the public page route, e.g. `blog/my-post`. `{id}` is the full channel id, including any colons, slashes, and `@` signs.

### Capabilities response

```json
{
  "transports": [
    { "id": "polling", "name": "HTTP Polling", "priority": 0, "messageTypes": ["crdt", "broadcast", "awareness"] },
    { "id": "mercure", "name": "Mercure (SSE)", "priority": 50, "messageTypes": ["crdt", "broadcast", "awareness"] }
  ],
  "preferred": "mercure",
  "polling": { "idle_interval_ms": 4000, "active_interval_ms": 1000 },
  "presence": { "ttl_seconds": 30 }
}
```

## Events

| Event | Listener | Payload | Purpose |
|-------|----------|---------|---------|
| `onSyncRegisterTransports` | Transport plugins | `transports` (registry), `sync` | Add a `TransportInterface` to the registry. |
| `onSyncRegisterChannels` | Consumer plugins | `sync`, `channels` | Eagerly register channels at boot. |
| `onSyncCheckAccess` | Consumer plugins | `channel`, `channel_id`, `user`, `action`, `allowed` | Fallback auth path when a channel has no `authCallback`. |
| `onSyncCapabilities` | Transport plugins | `capabilities` (mutable) | Add transport metadata to the capabilities response. |
| `onSyncUpdate` | Transport plugins | `room`, `clientId`, `update`, `updateBytes` | CRDT publish hook; fires on `/push` and successful `/init`. |
| `onSyncAwareness` | Transport plugins | `room`, `clientId`, `state` | Awareness publish hook; fires on `/presence`. |

Transport plugins typically subscribe to `onSyncRegisterTransports`, `onSyncCapabilities`, `onSyncUpdate`, and `onSyncAwareness`. Consumer plugins typically subscribe to `onSyncRegisterChannels` and (optionally) `onSyncCheckAccess`.

## 1.7 / 2.0 compatibility

Sync wires up exactly one HTTP entry path at `onPluginsInitialized`:

- If `\Grav\Plugin\Api\ApiRouteCollector` exists (api plugin loaded), sync subscribes to `onApiRegisterRoutes` and surfaces every endpoint at `/api/v1/sync/*` with api's middleware chain (auth, rate limiting, CORS, error mapping).
- Otherwise sync subscribes to `onPageInitialized` and dispatches matching `/sync/*` requests itself via `SyncLegacyRouter`. The legacy dispatcher resolves the user from the active session, decodes the JSON body, and routes into the same `SyncController` actions.

Both paths run on Grav 1.7.49+ and 2.0+ and are semantically identical from the controller's perspective.

## Configuration

Defaults live in `sync.yaml`; override in `user/config/plugins/sync.yaml`:

```yaml
enabled: true

storage:
  adapter: file        # file | sqlite (sqlite requires the database plugin)

squash:
  idle_seconds: 60     # squash after this much room inactivity
  max_log_bytes: 524288  # force-squash when log exceeds this size

presence:
  ttl_seconds: 30      # client considered gone after this many seconds without heartbeat

polling:
  idle_interval_ms: 4000   # client poll cadence when editing alone
  active_interval_ms: 1000 # client poll cadence when others are present
```

The polling intervals are advertised to clients via `GET /sync/capabilities`; clients use them as defaults.

## Permissions

Defined in `permissions.yaml` and registered with Grav's ACL:

| Permission | Granted for |
|------------|-------------|
| `api.collab.read`  | `pull`, `presence` |
| `api.collab.write` | `push`, `init`, presence with writes |

Normal page ACL is also enforced (`api.pages.read` for pulls, `api.pages.write` for pushes), so collaboration cannot escalate beyond what the user can already do via the page API. Channel-scoped pub/sub endpoints additionally consult `Sync::checkAccess()` so consumer plugins enforce their own per-channel rules.

## Storage layout (file adapter)

Per-room CRDT logs and snapshots live under `user/data/sync/`, keyed by a hash of the page route. Sync data never lands inside `user/pages/`.

```
user/data/sync/
├── <md5(route)>/
│   ├── meta.json       # route + template + lang reverse lookup
│   ├── default.log     # append-only Yjs updates: [BE uint32 length][bytes] ...
│   ├── default.state   # optional squashed snapshot
│   └── default.en.log  # explicit-language variant
└── broadcast/
    └── <channel-id-hash>/...   # broadcast TTL ring buffers
```

Concurrency is handled with `flock(LOCK_EX)` for appends and `LOCK_SH` for reads. Snapshot writes use rename-swap for atomicity. Room ids and channel ids are sanitized before path resolution so a malicious id cannot escape the sync data root.

## Room ids

A CRDT room id encodes route, template, and (optionally) language:

```
<route>@<template>             # default language
<route>@<template>@<lang>      # explicit language, e.g. blog/my-post@default@fr
```

`route` is the page folder path under `user/pages` with numeric ordering prefixes stripped (`blog/my-post`, not `01.blog/03.my-post`).

## Squash strategy

A page room is squashed back to its canonical Markdown file when **either**:

- it has had no presence for `squash.idle_seconds`, or
- the update log exceeds `squash.max_log_bytes`.

The squash is performed by a connected client (typically the last one to leave), which writes the merged content back through the page API; the server then truncates the log to the acknowledged offset.

## Companion plugins

- **[grav-plugin-sync-mercure](https://github.com/getgrav/grav-plugin-sync-mercure).** Adds Mercure SSE push so clients learn about updates instantly instead of waiting for the next poll. Listens for `onSyncRegisterTransports`, `onSyncUpdate`, and `onSyncCapabilities`.
- **grav-plugin-sync-ably.** Adds Ably cloud pub/sub as a managed alternative to running a Mercure hub.

## License

MIT, see [LICENSE](LICENSE).
