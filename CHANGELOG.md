# v1.0.1
## 05/05/2026

1. [](#bugfix)
    * **Sync data no longer lands inside `user/pages/`.** Per-room logs and snapshots used to be written next to the page they belonged to (e.g. `user/pages/02.typography/.sync/`), and the page route was used as a literal folder name — so language variants ended up in spurious siblings like `user/pages/typography.en/`, which Grav then listed in admin as extra pages. Storage now lives under `user/data/sync/<md5(route)>/` with a `meta.json` for reverse lookup, and language is encoded as a filename suffix (`default.en.log`) rather than baked into the folder name. Existing rooms under `user/pages/**/.sync/` should be deleted; the next edit reseeds cleanly.
    * **Room ids no longer fold the language into the route segment.** The format is now `<route>@<template>` (default language) or `<route>@<template>@<lang>` (explicit), matching the cleanly separated route/template/lang model the storage layer already used internally. Routes carrying a `.<lang>` suffix were a footgun for both the storage path and clients computing topic names.

# v1.0.0
## 04/25/2026

1. [](#new)
    * Initial Release
