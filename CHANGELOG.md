# v1.1.1
## 05/10/2026

1. [](#new)
    * Public pub/sub facade `$grav['sync']`. Plugins can register channels and publish messages without coupling to a specific transport.
    * Three message types: `crdt` (Yjs binary updates), `broadcast` (arbitrary payload with optional TTL replay), `awareness` (ephemeral presence).
    * Transport-provider registry. External plugins (Mercure, Ably) plug in via the new `onSyncRegisterTransports` event. Built-in polling stays as the universal fallback.
    * Per-channel auth delegation via callback or the new `onSyncCheckAccess` event.
    * New endpoints `/sync/channels/{id}/pull` and `/sync/channels/{id}/publish` for the broadcast pub/sub model. Available under both the api-plugin route prefix and the legacy `/sync/*` dispatcher.
    * Legacy `/sync/*` HTTP path now uses the api plugin's full auth chain (X-API-Token, Authorization Bearer) when api is loaded, in addition to session auth. The mutex with `/api/v1/sync/*` is unchanged; this enables forward-compat for sites that choose to expose both prefixes.
2. [](#improved)
    * Capabilities response now lists every registered transport with id, name, priority, and supported message types, plus a `preferred` field. The existing `polling` and `presence` sub-blocks are unchanged.
3. [](#note)
    * The CRDT path used by editor-pro is unchanged. All existing endpoints, events (`onSyncUpdate`, `onSyncAwareness`, `onSyncCapabilities`), and storage layouts remain compatible.

# v1.0.2
## 05/09/2026

1. [](#new)
    * Sync now runs on Grav 1.7 in addition to 2.0. The api plugin is no longer a hard dependency. When api is installed sync's endpoints are still served at `/api/v1/sync/*` exactly as before; otherwise sync provides its own minimal HTTP layer at `/sync/*`.
    * Self-contained PSR-7 HTTP base class (`Grav\Plugin\Sync\Http\AbstractSyncController`) replaces the prior dependency on the api plugin's `AbstractApiController`. Plugin authors extending sync's controllers should target the new base class.
2. [](#improved)
    * Composer dependencies updated to declare PSR-7 message and server-request packages directly. Run `composer install` inside the plugin after updating.

# v1.0.1
## 05/05/2026

1. [](#bugfix)
    * **Sync data no longer lands inside `user/pages/`.** Per-room logs and snapshots used to be written next to the page they belonged to (e.g. `user/pages/02.typography/.sync/`), and the page route was used as a literal folder name — so language variants ended up in spurious siblings like `user/pages/typography.en/`, which Grav then listed in admin as extra pages. Storage now lives under `user/data/sync/<md5(route)>/` with a `meta.json` for reverse lookup, and language is encoded as a filename suffix (`default.en.log`) rather than baked into the folder name. Existing rooms under `user/pages/**/.sync/` should be deleted; the next edit reseeds cleanly.
    * **Room ids no longer fold the language into the route segment.** The format is now `<route>@<template>` (default language) or `<route>@<template>@<lang>` (explicit), matching the cleanly separated route/template/lang model the storage layer already used internally. Routes carrying a `.<lang>` suffix were a footgun for both the storage path and clients computing topic names.

# v1.0.0
## 04/25/2026

1. [](#new)
    * Initial Release
