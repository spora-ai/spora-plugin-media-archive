# spora-plugin-media-archive

Admin UI for browsing, filtering, and downloading media archived by Spora agents.

This plugin contributes the **Media Archive** app to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-media-archive-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-media-archive
composer require spora-ai/spora-plugin-media-archive-frontend
```

Both packages are required: the PHP package contributes the admin-panel metadata (`MediaArchiveApp` → `VueAppInterface`), and the frontend package ships the Vue IIFE bundle that the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` ≥ 0.11.1 (ships the standalone `MediaAssetSerializer` that emits the asset `filename` on the wire — versions prior to 0.11.1 render `unknown` on the admin panel because the controller's inline `serialize()` omits the field).

## What it does

- Surfaces rows from the `media_assets` table (indexed by `MediaArchiveService`) as a filterable grid in the admin UI.
- Filters by media type, plugin, tool, agent, and date range.
- Click-through detail drawer with metadata (dimensions, duration, mime type, source URL).
- One-click download via the existing `AssetController::show()` route.

The plugin itself adds no tools, drivers, recipes, or migrations — it is purely presentational.

## Companion plugins

Listed in `composer.json` under `suggest`:

- `spora-ai/spora-plugin-minimax` — image, speech, music, and video generation. Rows produced by these tools show up here.
- `spora-ai/spora-plugin-email` — email send/receive. Outbound attachments surface as archived documents.

## Reference

The canonical reference (REST contract, ingestion API, retention guidance, configuration flags) lives on the docs site:

**[docs.spora-ai.com/develop/plugins/reference/media-archive](https://docs.spora-ai.com/develop/plugins/reference/media-archive)**

## License

MIT — see [LICENSE](LICENSE).