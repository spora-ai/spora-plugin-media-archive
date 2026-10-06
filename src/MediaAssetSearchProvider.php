<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;

/**
 * Makes every visible Media Archive asset findable in the host ⌘K palette.
 *
 * Reads {@see MediaAsset} directly rather than going through
 * {@see \Spora\Services\MediaArchive\MediaArchiveService::list()}: the list
 * endpoint's `search` covers `prompt` / `filename` / URLs only, ranks by
 * `created_at`, and paginates, none of which is what a palette row wants.
 * The ownership predicate below is the part that must not drift, and it is
 * deliberately the same shape.
 *
 * SCOPING — the rule, and the row it cannot reach.
 *
 * Scope is structural, as {@see SearchProviderInterface} demands: the query
 * is built from {@see SearchContext::principalIds()} and nothing else, so
 * there is no branch here that can be edited into widening. An empty
 * context returns `[]` before any query runs.
 *
 * The predicate is {@see \Spora\Services\MediaArchive\MediaArchiveService}'s
 * `applyPrincipalIdScope()`, and deliberately NOT this plugin's own
 * `MediaArchiveAdminController::canEdit()`:
 *
 *   - `canEdit()` is a *mutation* gate, and it answers a different
 *     question. It reads `user_id`, because `user_id` is the column that
 *     says who pushed the bytes. An admin passes it unconditionally.
 *   - `SearchContext` hands out principal ids, and core resolves them the
 *     same way for every caller — an admin's context is the admin's own
 *     visible principals, not "all of them". Adding an admin bypass here
 *     would be precisely the cross-tenant read the interface forbids.
 *   - `user_id` is not a visibility column. Migration 0075 backfilled
 *     `principal_id` for every row that had a `user_id` or an `agent_id`,
 *     so a `user_id`-only row (a legacy direct upload) is reachable
 *     through this predicate exactly as the archive grid already reaches
 *     it — through the uploader's user-principal, which
 *     `PrincipalResolver::visiblePrincipalIds()` always includes.
 *
 * What is therefore NOT findable here: rows with no attribution at all —
 * `principal_id IS NULL AND agent_id IS NULL`, i.e. anonymous ingestion,
 * a batch import, operator recovery. No principal names such a row, so no
 * context can ever contain it, and migration 0075's own docblock records
 * that they surface only under the archive's unscoped `ALL` chip. Widening
 * to reach them is not an option for a palette provider, so they stay out;
 * this is a real coverage gap for those rows, and the honest fix is an
 * owner column or a bulk backfill in core, not a provider that reads
 * rows nobody scoped.
 *
 * `agent_id` and `task_id` are not filtered on. A tool-generated asset
 * belongs to whoever owns its agent, which the agent-join branch already
 * establishes; `task_id` is a turn, not an owner, and nothing in
 * `SearchContext` speaks to it.
 */
final readonly class MediaAssetSearchProvider implements SearchProviderInterface
{
    /**
     * Palette section bucket, matching the `plugin.json` slug so the
     * palette labels the section from the same string the app registry,
     * the routes and the asset path all use. A stable slug, not a
     * human-facing label: it is half of the palette's dedup key.
     */
    private const TYPE = 'media-archive';

    /**
     * Matches core's `SkillSearchProvider`: the palette renders one bounded
     * list, and a media archive is a document set, not a fixed-size
     * affordance. The cap is a UX bound, not a security one — scope is
     * already applied above it.
     */
    private const MAX_HITS = 20;

    /**
     * Ranking tiers, best first. The number IS the rank and the order of
     * this list IS the ranking, so a new tier has to be inserted where it
     * belongs rather than appended.
     *
     * Filename outranks every body field on purpose: a prompt, a tag, and
     * especially a transcript are prose, and prose is weak evidence. The
     * palette's own client-side search has the same problem — filtering its
     * item list by `subLabel` is how a group called "Media" outranks the
     * document actually typed. A filename is a name, and a name is what
     * somebody means to find. Within the body, `prompt` first (what the
     * asset was *for*), then `tags` (short and deliberate), then
     * `transcript` last (long, machine-generated, and near-certain to
     * contain any common word).
     */
    private const RANK_FILENAME_EXACT = 0;
    private const RANK_FILENAME_PREFIX = 1;
    private const RANK_FILENAME_SUBSTRING = 2;
    private const RANK_PROMPT = 3;
    private const RANK_TAGS = 4;
    private const RANK_TRANSCRIPT = 5;

    /**
     * `lower(<column>) LIKE ?` and the escape clause, so every LIKE in this
     * class folds its column through `lower()` and shares one escape
     * character.
     *
     * The escape character is `!`, not the conventional `\`. `ESCAPE` takes a
     * one-character string literal, and MySQL's literals process backslash
     * escapes, so `'\'` is not reliably one character there — SQLite and
     * MySQL disagree about what the same clause means. `!` is inert in both,
     * and an escaped `!` is `!!`, which neither engine treats specially.
     */
    private const LIKE_PREFIX = 'lower(media_assets.';
    private const LIKE_ESCAPE = " ESCAPE '!' ";

    /**
     * A prompt is a paragraph; a palette row is one line. The host UI
     * truncates visually, but sending 4 KB of prompt text per hit to
     * render one clipped line is waste on every keystroke of a debounced
     * search, and the tail of a prompt is rarely the identifying part.
     */
    private const SUB_LABEL_LIMIT = 160;

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return list<SearchHit>
     */
    public function search(string $query, SearchContext $context): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        $principalIds = $this->principalIds($context);
        if ($principalIds === []) {
            return [];
        }

        $assets = $this->query($principalIds, $needle)->get();

        $hits = [];
        foreach ($assets as $asset) {
            $hits[] = new SearchHit(
                type: $this->type(),
                id: $asset->id,
                label: $this->labelFor($asset),
                subLabel: $this->subLabelFor($asset),
                badge: $this->badgeFor($asset),
                href: '/apps/media-archive/asset/' . rawurlencode($asset->id),
            );
        }

        return $hits;
    }

    /**
     * Deduplicated, validated principal ids — the whole of the scope.
     *
     * De-duplicated because a user who is in two groups can legitimately
     * see the same asset through either of their principals, and one asset
     * gets one hit; a repeated id would otherwise spend a second slot of
     * `MAX_HITS` on a row the first one already produced. Ids `<= 0` are
     * dropped because they name no principal.
     *
     * @return list<int>
     */
    private function principalIds(SearchContext $context): array
    {
        $ids = [];
        foreach ($context->principalIds() as $principalId) {
            if ($principalId > 0) {
                $ids[$principalId] = true;
            }
        }

        return array_map(intval(...), array_keys($ids));
    }

    /**
     * The whole query in one method: scope, text filter, ranking, order, cap.
     *
     * Assembled statement-by-statement on a local rather than as one fluent
     * chain, because a chained `->get()` loses its model type at the chain
     * boundary: the builder's methods each declare `Builder<TModel>`, but
     * `where(Closure)` widens to `Builder<Model>` at level 5 and every row
     * that comes back out is a `stdClass`. Locals keep the inferred type, so
     * a row is a `MediaAsset` all the way to {@see self::labelFor()}.
     *
     * @param list<int> $principalIds
     * @return Builder<MediaAsset>
     */
    private function query(array $principalIds, string $needle): Builder
    {
        $builder = MediaAsset::query();

        // Mirrors `applyPrincipalIdScope()` exactly: the indexed column
        // is the fast path, and the agent join is the back-compat path
        // for legacy rows whose `principal_id` stayed NULL.
        $builder->where(static function (Builder $q) use ($principalIds): void {
            $q->whereIn('media_assets.principal_id', $principalIds)
                ->orWhere(static function (Builder $sub) use ($principalIds): void {
                    $sub->whereNull('media_assets.principal_id')
                        ->whereIn('media_assets.agent_id', Agent::query()
                            ->select('id')
                            ->whereIn('principal_id', $principalIds));
                });
        });

        // A derivative is a full `media_assets` row that inherits its
        // parent's `principal_id`, so without this a PDF and the `md`
        // rendered out of it are two hits for one document. The archive
        // grid hides them for the same reason and reaches them through
        // the detail page's versions strip instead.
        $builder->whereNotIn(
            'media_assets.id',
            MediaDerivative::query()->select('derivative_id'),
        );

        // Also the archive grid's default. Temporary rows are
        // short-lived voice transcripts, so leaving them in would let a
        // burst of them push real documents out of `MAX_HITS`.
        $builder->where('media_assets.is_temporary', false);

        $builder->where($this->textFilter($needle));
        $builder->orderByRaw($this->rankSql(), $this->rankBindings($needle));
        // Ties are broken all the way down, so two identical queries cannot
        // return the same rows in a different order.
        $builder->orderByDesc('created_at');
        $builder->orderBy('id');
        // The cap rides on the ORDER BY rather than a slice afterwards: the
        // rows that survive LIMIT are the best-ranked ones, rather than the
        // best-ranked of an arbitrary window.
        $builder->limit(self::MAX_HITS);

        return $builder;
    }

    /**
     * Which rows match at all, independent of how well they match.
     *
     * `filename` / `prompt` / `transcript` are plain columns; `tags` is a
     * JSON array cast to a PHP array on read, so the LIKE runs against its
     * serialised form. That is substring matching over the tag text, which
     * is what the palette is for; it is not an exact-tag lookup, and it
     * does not need to be.
     */
    private function textFilter(string $needle): Closure
    {
        $contains = self::pattern($needle, true, true);

        return static function (Builder $q) use ($contains): void {
            $q->where(static function (Builder $inner) use ($contains): void {
                $inner->whereRaw(self::LIKE_PREFIX . 'filename) LIKE ?' . self::LIKE_ESCAPE, [$contains])
                    ->orWhereRaw(self::LIKE_PREFIX . 'prompt) LIKE ?' . self::LIKE_ESCAPE, [$contains])
                    ->orWhereRaw(self::LIKE_PREFIX . 'transcript) LIKE ?' . self::LIKE_ESCAPE, [$contains])
                    ->orWhereRaw(self::LIKE_PREFIX . 'tags) LIKE ?' . self::LIKE_ESCAPE, [$contains]);
            });
        };
    }

    /**
     * The same tiers as {@see self::RANK_*} as a SQL expression, ordered
     * `lower(...)` so the tiers are accent-insensitive on ASCII on both
     * engines — SQLite's `LIKE` is case-insensitive for ASCII but not for
     * accents, MySQL's collation is, and the palette should not rank
     * differently depending on which engine the install runs.
     *
     * The LIKE patterns arrive as bindings rather than being concatenated in
     * SQL: SQLite has no `CONCAT` before 3.44, and MySQL's `||` is a logical
     * OR unless `PIPES_AS_CONCAT` is set, so an inline concatenation is the
     * kind of thing that works on the engine you develop against and fails
     * on the one you deploy against.
     *
     * Ranking in SQL rather than in PHP is what makes the cap correct: the
     * best-ranked rows are the ones that survive `LIMIT`, instead of a
     * newest-first window being re-scored after the fact.
     */
    private function rankSql(): string
    {
        return 'CASE'
            . ' WHEN lower(media_assets.filename) = ? THEN ' . self::RANK_FILENAME_EXACT
            . ' WHEN lower(media_assets.filename) LIKE ?' . self::LIKE_ESCAPE . ' THEN ' . self::RANK_FILENAME_PREFIX
            . ' WHEN lower(media_assets.filename) LIKE ?' . self::LIKE_ESCAPE . ' THEN ' . self::RANK_FILENAME_SUBSTRING
            . ' WHEN lower(media_assets.prompt) LIKE ?' . self::LIKE_ESCAPE . ' THEN ' . self::RANK_PROMPT
            . ' WHEN lower(media_assets.tags) LIKE ?' . self::LIKE_ESCAPE . ' THEN ' . self::RANK_TAGS
            . ' ELSE ' . self::RANK_TRANSCRIPT
            . ' END ASC';
    }

    /**
     * Bindings for {@see self::rankSql()}, in the order its `?`s appear.
     *
     * @return list<string>
     */
    private function rankBindings(string $needle): array
    {
        $contains = self::pattern($needle, true, true);

        return [
            $needle,
            self::pattern($needle, false, true),
            $contains,
            $contains,
            $contains,
        ];
    }

    /**
     * A LIKE pattern for `$needle`, wildcard-escaped.
     *
     * Escaped because the palette forwards raw keystrokes: a typed `%`
     * would otherwise match every row in the principal's scope. Core's list
     * endpoint escapes for its `id` column only and leaves the prose
     * columns unescaped, and it takes a value from a filter field; this
     * takes one fired on every debounced keystroke, so it is the case where
     * an unescaped wildcard is actually reachable.
     *
     * See {@see self::LIKE_ESCAPE} for why the escape character is `!`.
     */
    private static function pattern(string $needle, bool $leadWildcard, bool $trailWildcard): string
    {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $needle,
        );

        return ($leadWildcard ? '%' : '') . $escaped . ($trailWildcard ? '%' : '');
    }

    /**
     * `filename` is what an operator recognises, and it is the only label
     * the archive UI shows. A tool-generated asset often has none, so the
     * id stands in rather than an empty row; `SearchHit::$label` is
     * non-nullable and a blank row in a palette is worse than a uuid.
     */
    private function labelFor(MediaAsset $asset): string
    {
        $filename = trim((string) $asset->filename);

        return $filename !== '' ? $filename : $asset->id;
    }

    /**
     * The prompt, because it is the one field that says what the asset was
     * *for* — the question somebody is usually asking when they search the
     * archive instead of browsing it. Null when there is none: the badge
     * already carries the type, and an empty string would render as a
     * blank line rather than as "no sublabel".
     */
    private function subLabelFor(MediaAsset $asset): ?string
    {
        $prompt = trim((string) $asset->prompt);
        if ($prompt === '') {
            return null;
        }

        return mb_strlen($prompt) > self::SUB_LABEL_LIMIT
            ? mb_substr($prompt, 0, self::SUB_LABEL_LIMIT - 1) . '…'
            : $prompt;
    }

    /**
     * `media_type` first: it is a closed vocabulary (`image`, `audio`,
     * `video`, `document`, `unknown`) that reads as a category at a glance.
     * `mime_type` is the fallback for a row whose `media_type` is null or
     * unrecognised — more precise but noisier. Null only when the row has
     * neither, which is a hit with no type rather than a wrong one.
     */
    private function badgeFor(MediaAsset $asset): ?string
    {
        $mediaType = trim((string) $asset->media_type);

        return $mediaType !== '' ? $mediaType : ($asset->mime_type !== null ? $asset->mime_type : null);
    }
}
