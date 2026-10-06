# spora-plugin-media-archive

Admin UI for browsing, filtering, and downloading media archived by Spora agents.

This plugin contributes the **Media Archive** app to the host's Apps dropdown. The panel is a pre-built Vue SPA delivered as a separate Composer package (`spora-ai/spora-plugin-media-archive-frontend`, type `spora-plugin-frontend`). The two-package split lets the frontend evolve on its own release cadence and lets backend-only operators skip the bundle entirely.

## Install

```bash
composer require spora-ai/spora-plugin-media-archive
composer require spora-ai/spora-plugin-media-archive-frontend
```

Both packages are required: the PHP package contributes the admin-panel metadata (`MediaArchiveApp` → `VueAppInterface`), and the frontend package ships the Vue IIFE bundle that the host SPA lazy-loads at runtime.

Requires `spora-ai/spora-core` ≥ 0.30.0, which is where `Spora\Search\SearchProviderInterface` landed (core #279) — the plugin's search provider implements it, and 0.30.0 is also the first version whose ingest pipeline the plugin's tests are written against.

## What it does

- Surfaces rows from the `media_assets` table (indexed by `MediaArchiveService`) as a filterable grid in the admin UI.
- Filters by media type, plugin, tool, agent, and date range.
- Scope chip row (mirrors the dashboard's ALL / My Media / Group pattern) so a user with multiple groups can isolate each group's media. The chip row reads `/principals/me` + `/groups` to populate the labels; the controller intersects `?principal_id=` with the caller's `visiblePrincipalIds()` so an out-of-scope principal id is silently dropped.
- Click-through detail drawer with metadata (dimensions, duration, mime type, source URL).
- A `derivatives` strip below the asset row (format chip + producer + asset URL). Populated from the `media_derivatives` join table; the controller reads it via `MediaDerivativeService::listFor()` so chips survive a hard reload, not just the in-memory splice on derivative production.
- One-click download via the existing `AssetController::show()` route.
- Palette search (⌘K): `MediaAssetSearchProvider` makes archive assets findable by name or by content, under the `media-archive` section of the host palette. See [Palette search (⌘K)](#palette-search-⌘k) below.

The plugin itself adds no tools, drivers, recipes, or migrations.

## Palette search (⌘K)

`MediaAssetSearchProvider` implements `Spora\Search\SearchProviderInterface` — the seam core ships no implementation of, so every section the palette renders from the server comes from a plugin. The host calls `GET /api/v1/search` on every debounced keystroke and this plugin answers with archive rows.

- **Section bucket `media-archive`** — the `plugin.json` slug, not a human-facing label. The palette title-cases `type` into the section header and the registry de-duplicates on `type::id`, so the string is a key.
- **Matched fields:** `filename`, plus `prompt`, `transcript` and `tags` — so an asset is findable by what it contains, not only by what it is called. All four are matched in SQL; `tags` is matched against its serialised JSON form, which is substring matching over the tag text rather than an exact-tag lookup.
- **Ranked filename-first:** exact name, then prefix, then substring, then `prompt`, then `tags`, then transcript-only. A prompt, a tag and especially a transcript are prose, and prose is weak evidence; a filename is a name, and a name is what somebody means to find. The ranking runs in SQL rather than in PHP, so the cap keeps the twenty best rows instead of twenty of an arbitrary window.
- **Scoped to the caller's principals:** the query is built from `SearchContext::principalIds()` and nothing else — no branch to widen, and an empty context returns nothing without reaching the database. There is deliberately no admin bypass; an admin's context is the admin's own visible principals. Rows with no attribution at all (`principal_id` and `agent_id` both null) are consequently not findable — the class docblock records why, and what would close that gap.
- **Bounded at 20 hits, globally across the context's principals** — a busy group can fill every slot and hide another principal's asset. The archive's own scope chips are where "I mean that group" belongs.
- **Not offered:** derivatives (the `md` rendered out of a `pdf` is not a second document) and temporary rows, matching what the archive grid hides.

A hit shows the filename as its label, a truncated prompt as its sublabel and the media type — or the mime type as a fallback — as its badge, and opens `/apps/media-archive/asset/{id}` on select.

## Companion plugins

This plugin has no `suggest` block. Producer plugins (spora-plugin-minimax,
spora-plugin-openai-image, spora-plugin-typst) declare media-archive as a
companion suggestion in their own detail dialogs, so installing any of them
surfaces media-archive as an installable companion on the producer side.
See each producer's README for the current list.

## Reference

The canonical reference (REST contract, ingestion API, retention guidance, configuration flags) lives on the docs site:

**[docs.spora-ai.com/develop/plugins/reference/media-archive](https://docs.spora-ai.com/develop/plugins/reference/media-archive)**

<!--
  KNOWN GAP: that URL 404s. spora-docs/docs/develop/plugins/reference/ has no
  media-archive.md, so the canonical page for this plugin has not been written
  yet. The link is left as-is rather than repointed at a substitute: where this
  page eventually lives is spora-docs' decision, not this plugin's, and a link
  that silently points somewhere else is worse than one that is visibly not
  there yet. Written when the reference page lands — do not treat the 404 as a
  broken mirror of an existing page.
-->

## License

MIT — see [LICENSE](LICENSE).