# spora-plugin-media-archive

Admin UI for browsing, filtering, and downloading media archived by Spora agents.

This plugin contributes the **Media Archive** app to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-media-archive-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-media-archive
composer require spora-ai/spora-plugin-media-archive-frontend
```

Both packages are required: the PHP package contributes the admin-panel metadata (`MediaArchiveApp` → `VueAppInterface`), and the frontend package ships the Vue IIFE bundle that the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` ≥ 0.20.0 (ships `MediaDerivativeService` and the two-arg `MediaAssetSerializer` used to surface persisted derivatives on the detail page; versions prior to 0.20.0 leave the VersionsStrip empty on reload).

## What it does

- Surfaces rows from the `media_assets` table (indexed by `MediaArchiveService`) as a filterable grid in the admin UI.
- Filters by media type, plugin, tool, agent, and date range.
- Scope chip row (mirrors the dashboard's ALL / My Media / Group pattern) so a user with multiple groups can isolate each group's media. The chip row reads `/principals/me` + `/groups` to populate the labels; the controller intersects `?principal_id=` with the caller's `visiblePrincipalIds()` so an out-of-scope principal id is silently dropped.
- Click-through detail drawer with metadata (dimensions, duration, mime type, source URL).
- A `derivatives` strip below the asset row (format chip + producer + asset URL). Populated from the `media_derivatives` join table; the controller reads it via `MediaDerivativeService::listFor()` so chips survive a hard reload, not just the in-memory splice on derivative production.
- One-click download via the existing `AssetController::show()` route.

The plugin itself adds no tools, drivers, recipes, or migrations — it is purely presentational.

## Companion plugins

This plugin has no `suggest` block. Producer plugins (spora-plugin-minimax,
spora-plugin-openai-image, spora-plugin-typst) declare media-archive as a
companion suggestion in their own detail dialogs, so installing any of them
surfaces media-archive as an installable companion on the producer side.
See each producer's README for the current list.

## Reference

The canonical reference (REST contract, ingestion API, retention guidance, configuration flags) lives on the docs site:

**[docs.spora-ai.com/develop/plugins/reference/media-archive](https://docs.spora-ai.com/develop/plugins/reference/media-archive)**

## License

MIT — see [LICENSE](LICENSE).