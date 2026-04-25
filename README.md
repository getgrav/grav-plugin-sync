# Grav Sync Plugin

Real-time collaboration substrate for [Grav CMS](https://getgrav.org) 2.0.

Sync is the server side of multi-user editing in the new Admin: it stores [Yjs](https://yjs.dev) CRDT updates and presence state for any page, then squashes them back into the canonical Markdown file when collaborators leave. The plugin is **transport-agnostic** — clients can poll over plain HTTP, or a companion plugin can layer Mercure / WebSocket push on top.

## What it does

- **CRDT storage** — Append-only log of opaque Yjs update bytes per page, with optional squashed snapshots. The PHP server does no CRDT decoding; merging happens in the browser.
- **Presence** — Tracks who is currently editing a page (heartbeat-based, with TTL).
- **Race-free init** — Atomic empty-room seed so two clients opening a fresh page can't double-apply initial state.
- **Squash to source** — When a room goes idle, the accumulated CRDT log is folded back into the underlying `*.md` file and the log is truncated.
- **Transport hooks** — Fires events that companion plugins (e.g. `grav-plugin-sync-mercure`) use to push updates to subscribers without changing the client API.

## Requirements

- Grav CMS 2.0+
- PHP 8.3+
- API plugin `>= 1.0.0-beta.13`
- Login plugin `>= 3.8.0`

## Installation

### GPM (preferred)

```bash
bin/grav install sync
```

### Manual

1. Clone or download this repository into `user/plugins/sync`.
2. Run `composer install` in the plugin directory.
3. Enable the plugin in Admin or via `user/config/plugins/sync.yaml`.

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

The polling intervals are advertised to clients via `GET /sync/capabilities` — clients use them as defaults.

## HTTP API

All endpoints are mounted under the API plugin's prefix (default `/api/v1`). Binary Yjs updates ride inside JSON as base64 strings.

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/sync/capabilities` | Discover transports, polling defaults, presence TTL |
| `POST` | `/sync/pages/{route}/init` | Atomically seed an empty room (resolves first-open race) |
| `POST` | `/sync/pages/{route}/pull` | Fetch updates since an opaque cursor |
| `POST` | `/sync/pages/{route}/push` | Append a binary update to the room's log |
| `POST` | `/sync/pages/{route}/presence` | Heartbeat / leave the room |

`{route}` is the public page route, e.g. `blog/my-post`. Body fields like `lang` and `template` further qualify the room id.

### Capabilities response

```json
{
  "transports": ["polling"],
  "preferred": "polling",
  "polling": { "idle_interval_ms": 4000, "active_interval_ms": 1000 },
  "presence": { "ttl_seconds": 30 }
}
```

A transport plugin may enrich this — for example, `sync-mercure` adds an SSE hub URL and bumps `preferred`.

## Permissions

Defined in `permissions.yaml` and registered with Grav's ACL:

| Permission | Granted for |
|------------|-------------|
| `api.collab.read`  | `pull`, `presence` |
| `api.collab.write` | `push`, `init`, presence with writes |

In addition, normal page ACL is enforced — `api.pages.read` for pulls, `api.pages.write` for pushes — so collaboration cannot escalate beyond what the user can already do via the page API.

## Events for transport plugins

Two events let companion plugins layer real-time push on top of the polling baseline:

| Event | Payload | Fired by |
|-------|---------|----------|
| `onSyncCapabilities` | `['capabilities' => array]` (mutable) | `GET /sync/capabilities` |
| `onSyncUpdate` | `['room', 'clientId', 'update', 'updateBytes']` | `POST /sync/pages/{route}/push` and successful `init` |

Subscribers should mutate `$event['capabilities']` to advertise themselves and react to `onSyncUpdate` to republish updates onto their channel.

## Storage layout (file adapter)

For each page, sidecar files live alongside the Markdown source:

```
user/pages/blog/my-post/
├── default.md
└── .sync/
    ├── default.log     # append-only Yjs updates: [BE uint32 length][bytes] …
    └── default.state   # optional squashed snapshot
```

Concurrency is handled with `flock(LOCK_EX)` for appends and `LOCK_SH` for reads. Snapshot writes use rename-swap for atomicity. Room ids are sanitized before path resolution so a malicious id can't escape the pages root.

## Room ids

A room id encodes route, language, and template:

```
<route>@<template>             # default language
<route>.<lang>@<template>      # explicit language, e.g. blog/my-post.fr@default
```

`route` is the page folder path under `user/pages` with numeric ordering prefixes stripped (`blog/my-post`, not `01.blog/03.my-post`).

## Squash strategy

A page room is squashed back to its canonical Markdown file when **either**:

- it has had no presence for `squash.idle_seconds`, **or**
- the update log exceeds `squash.max_log_bytes`.

The squash is performed by a connected client (typically the last one to leave), which writes the merged content back through the page API; the server then truncates the log to the acknowledged offset.

## Companion plugins

- **[grav-plugin-sync-mercure](https://github.com/getgrav/grav-plugin-sync-mercure)** — Adds Mercure SSE push so clients learn about updates instantly instead of waiting for the next poll. Listens for `onSyncUpdate`, advertises itself via `onSyncCapabilities`.

## License

MIT — see [LICENSE](LICENSE).
