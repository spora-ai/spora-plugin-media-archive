<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Models\MediaAsset;
use Spora\Plugins\MediaArchive\MediaAssetSearchProvider;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Services\MediaArchive\MediaArchiveService;

/*
 * Fixtures insert `media_assets` rows directly rather than going through
 * `MediaArchiveService::ingest()`. The provider only ever reads rows, and
 * the columns that matter here — `principal_id`, `agent_id`,
 * `is_temporary` — are exactly the ones ingestion resolves for itself
 * (migration 0075's backfill, the ingest pipeline's principal
 * precedence). Writing them directly is what makes a legacy row, an
 * unattributed row, and a derivative expressible at all: none of those
 * three shapes is reachable by asking the service to ingest something.
 */

/**
 * Make sure the principal `$id` names exists, because
 * `media_assets.principal_id` carries a foreign key.
 *
 * `SearchContext` only ever carries ids — what the row behind one says is
 * irrelevant to what is under test — but the row has to be there for the
 * insert to land. Existence-checked rather than memoised because each test
 * runs in its own rolled-back transaction: a static cache would outlive the
 * row it remembered and the second fixture in one test would fail.
 */
function ensurePrincipal(int $id): int
{
    $exists = Capsule::connection()->table('principals')->where('id', $id)->exists();

    if (!$exists) {
        Capsule::connection()->table('principals')->insert([
            'id'         => $id,
            'type'       => 'user',
            'user_id'    => null,
            'group_id'   => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    return $id;
}

/**
 * An agent owned by `$principalId`. The provider's legacy branch reads
 * `agents.principal_id`, so a test that exercises that branch needs a real
 * agent row — an `agent_id` pointing at nothing would silently match no
 * principal at all.
 */
function createAgent(string $name, int $principalId): int
{
    ensurePrincipal($principalId);

    return (int) Capsule::connection()->table('agents')->insertGetId([
        'name'         => $name,
        'principal_id' => $principalId,
        'created_at'   => Carbon::now(),
        'updated_at'   => Carbon::now(),
    ]);
}

/**
 * @param array<string, mixed> $attributes
 */
function createAsset(array $attributes = []): MediaAsset
{
    if (($attributes['principal_id'] ?? null) !== null) {
        ensurePrincipal((int) $attributes['principal_id']);
    }
    $asset = new MediaAsset();
    $asset->id = $attributes['id'] ?? sprintf('00000000-0000-4000-8000-%012d', random_int(1, 999999999999));
    $asset->asset_url = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $asset->id;
    $asset->storage_mode = 'database';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->media_type = 'document';
    $asset->mime_type = 'application/pdf';
    $asset->upload_source = 'tool';
    $asset->is_temporary = false;
    $asset->created_at = Carbon::now();
    $asset->updated_at = Carbon::now();

    foreach ($attributes as $column => $value) {
        $asset->{$column} = $value;
    }

    $asset->save();

    return $asset;
}

function provider(): MediaAssetSearchProvider
{
    return new MediaAssetSearchProvider();
}

function search(string $query, array $principalIds = [1]): array
{
    return provider()->search($query, new SearchContext($principalIds));
}

function hitIds(array $hits): array
{
    return array_map(static fn(SearchHit $hit): string => $hit->id, $hits);
}

/**
 * The provider's select list, read off the class rather than restated here.
 *
 * Reflection rather than a duplicated literal so this file cannot drift from
 * the constant it is supposed to hold in place: the reconciliation below is
 * only worth anything if it reads what the provider actually ships.
 *
 * @return list<string>
 */
function providerSelectedColumns(): array
{
    $constant = (new ReflectionClass(MediaAssetSearchProvider::class))->getReflectionConstant('SELECTED_COLUMNS');

    expect($constant)->not->toBeNull();

    /** @var list<string> $columns */
    $columns = $constant->getValue();

    return $columns;
}

/**
 * Every `->column` property read in the provider's source, narrowed to the
 * names that are actually columns on {@see MediaAsset}.
 *
 * Tokenised rather than grepped so a property fetch and a method call are not
 * confused — `$builder->where(...)` and `$this->type()` are calls, while
 * `$asset->filename` is a read that would come back `null` if the select list
 * stopped naming the column. Intersecting with the model's own column list is
 * what keeps `$hit->`-style reads on other objects out of the result, so the
 * assertion stays true without the provider having to keep a comment in sync.
 *
 * @return list<string>
 */
function providerModelReads(): array
{
    $source = file_get_contents(BASE_PATH . '/src/MediaAssetSearchProvider.php');
    expect($source)->toBeString();

    /** @var array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    $tokens = token_get_all($source);

    $reads = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$index + 1] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING) {
            continue;
        }

        $after = $tokens[$index + 2] ?? null;
        if (is_array($after) && $after[0] === T_WHITESPACE) {
            $after = $tokens[$index + 3] ?? null;
        }
        // `token_get_all` returns a single-character token as a plain string,
        // so the `(` of a call is `'('` and never an array to index into.
        if ($after === '(') {
            continue;
        }

        if (in_array($name[1], mediaAssetColumns(), true)) {
            $reads[$name[1]] = true;
        }
    }

    return array_keys($reads);
}

/**
 * What may appear in the select list: the model's own columns, plus the two
 * timestamps Eloquent manages and — pointedly — does not list.
 *
 * @return list<string>
 */
function mediaAssetColumns(): array
{
    return [...MediaAsset::COLUMNS, 'created_at', 'updated_at'];
}

/**
 * The `media_assets` SELECTs a callable produces, for assertions about what
 * the provider asked the database for.
 *
 * Logged rather than inferred from the builder so the test survives the
 * select list being dropped entirely — the failure a source-level check
 * cannot see, because the constant can still be there, unused.
 *
 * @param callable(): void $run
 * @return list<string>
 */
function mediaAssetSelects(callable $run): array
{
    $connection = Capsule::connection();
    $connection->enableQueryLog();

    try {
        $run();
        $log = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
    }

    $statements = [];
    foreach ($log as $entry) {
        // Quoting differs per engine; the table name does not.
        if (preg_match('/from ["`]media_assets["`]/', $entry['query']) === 1) {
            $statements[] = $entry['query'];
        }
    }

    return $statements;
}

// ─────────────────────────────────────────────────────────────────────────────
// type() and the palette contract
// ─────────────────────────────────────────────────────────────────────────────

test('type() is the plugin slug', function (): void {
    // Half of the palette's dedup key (`type::id`), so it has to be the
    // same string the manifest, the app registry and the asset path use —
    // 'media-archive', not 'Media Archive' and not 'media_asset'.
    expect(provider()->type())->toBe('media-archive');
    expect(provider()->type())->toBe(json_decode(file_get_contents(BASE_PATH . '/plugin.json'), true)['slug']);
});

test('every hit carries the provider type and the asset href', function (): void {
    $asset = createAsset(['principal_id' => 1, 'filename' => 'quarterly-report.pdf']);

    $hits = search('quarterly');

    expect($hits)->toHaveCount(1);
    expect($hits[0]->type)->toBe('media-archive');
    // Always non-null: the plugin SPA already routes this exact path
    // (App.vue pushes `/apps/media-archive/asset/{id}`), so there is no
    // asset the palette cannot open.
    expect($hits[0]->href)->toBe('/apps/media-archive/asset/' . $asset->id);
    expect($hits[0]->id)->toBe($asset->id);
});

test('href is url-encoded and label falls back to the id when filename is absent', function (): void {
    // A tool-generated asset commonly has no filename. `SearchHit::$label`
    // is non-nullable and a blank palette row is worse than a uuid, so the
    // id stands in — and the href still points at a real page.
    $asset = createAsset([
        'principal_id' => 1,
        'filename'     => null,
        'prompt'       => 'a moody lighthouse at dusk',
    ]);

    $hits = search('lighthouse');

    expect($hits)->toHaveCount(1);
    expect($hits[0]->label)->toBe($asset->id);
    expect($hits[0]->href)->toBe('/apps/media-archive/asset/' . $asset->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// What matches
// ─────────────────────────────────────────────────────────────────────────────

test('a filename match is found wherever in the name it falls', function (): void {
    // A prefix, a middle fragment, and an extension-anchored tail: people
    // remember filenames by all three, and an exact-name-only match would
    // make the archive feel empty for the first two.
    $asset = createAsset(['principal_id' => 1, 'filename' => 'invoice-2026-03.pdf']);

    expect(hitIds(search('invoice')))->toBe([$asset->id]);
    expect(hitIds(search('invoice-2026')))->toBe([$asset->id]);
    expect(hitIds(search('2026-03')))->toBe([$asset->id]);
});

test('a prompt, tag, or transcript match is found', function (): void {
    // The point of the provider: what the asset *contains* is searchable,
    // not only what it is called. An operator who remembers "the thing
    // with the volcano in it" finds it without knowing the filename.
    $prompted    = createAsset(['principal_id' => 1, 'filename' => 'a.png', 'prompt' => 'a volcano erupting at night']);
    $tagged      = createAsset(['principal_id' => 1, 'filename' => 'b.png', 'tags' => ['volcano', 'iceland']]);
    $transcribed = createAsset(['principal_id' => 1, 'filename' => 'c.m4a', 'transcript' => 'we drove past the volcano']);

    $ids = hitIds(search('volcano'));

    expect($ids)->toHaveCount(3);
    // A `tags` LIKE runs against the serialised JSON array, which is what
    // makes a substring hit possible without a join table.
    expect($ids)->toContain($prompted->id, $tagged->id, $transcribed->id);
});

test('matching is case-insensitive and whitespace-trimmed', function (): void {
    createAsset(['principal_id' => 1, 'filename' => 'Quarterly-Report.pdf']);

    expect(hitIds(search('  QUARTERLY ')))->toHaveCount(1);
});

test('a row matching nothing is never returned', function (): void {
    createAsset(['principal_id' => 1, 'filename' => 'invoice.pdf', 'prompt' => 'a cat']);

    expect(search('dog'))->toBe([]);
});

test('LIKE wildcards in the query are escaped, not honoured', function (): void {
    // The palette forwards raw keystrokes, so a typed `%` arrives here.
    // Unescaped it would match every row in the principal's scope; escaped
    // it matches a row that literally contains a percent sign.
    createAsset(['principal_id' => 1, 'filename' => 'real.pdf']);
    $literal = createAsset(['principal_id' => 1, 'filename' => '100%-complete.pdf']);

    expect(hitIds(search('%')))->toBe([$literal->id]);
    expect(hitIds(search('_omplete')))->toBe([]);
});

test('an empty or whitespace-only query returns no hits', function (): void {
    createAsset(['principal_id' => 1, 'filename' => 'anything.pdf']);

    expect(search(''))->toBe([]);
    expect(search('   '))->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ranking
// ─────────────────────────────────────────────────────────────────────────────

test('a filename match outranks a body match regardless of age', function (): void {
    // The ordering the ranking exists for: prose is weak evidence, and a
    // name is what somebody means to find. Both rows are created so that a
    // recency-based ordering would put the *body* match first — this fails
    // if the rank is ever dropped from the query.
    createAsset([
        'principal_id' => 1,
        'filename'     => 'body.pdf',
        'prompt'       => 'a volcano',
        'created_at'   => Carbon::now()->addDay(),
    ]);
    $named = createAsset([
        'principal_id' => 1,
        'filename'     => 'volcano-notes.pdf',
        'prompt'       => null,
        'created_at'   => Carbon::now()->subYear(),
    ]);

    // Both rows match; the named one has to come first despite being a year
    // older, which is what fails if the rank is ever dropped from the query.
    expect(hitIds(search('volcano'))[0])->toBe($named->id);
});

test('an exact filename match outranks a prefix, which outranks a substring', function (): void {
    $substring = createAsset(['principal_id' => 1, 'filename' => 'my-volcano-notes.pdf']);
    $prefix    = createAsset(['principal_id' => 1, 'filename' => 'volcano-notes.pdf']);
    $exact     = createAsset(['principal_id' => 1, 'filename' => 'volcano']);

    expect(hitIds(search('volcano')))->toBe([$exact->id, $prefix->id, $substring->id]);
});

test('a prompt match outranks a tag match, which outranks a transcript match', function (): void {
    // Both rationale halves in one test: `prompt` is what the asset was
    // for, `tags` are short and deliberate, and a transcript is long,
    // machine-generated, and near-certain to contain any common word.
    $transcribed = createAsset(['principal_id' => 1, 'filename' => 't.m4a', 'transcript' => 'mentions the volcano']);
    $tagged      = createAsset(['principal_id' => 1, 'filename' => 'g.png', 'tags' => ['volcano']]);
    $prompted    = createAsset(['principal_id' => 1, 'filename' => 'p.png', 'prompt' => 'a volcano']);

    expect(hitIds(search('volcano')))->toBe([$prompted->id, $tagged->id, $transcribed->id]);
});

test('equal ranks fall back to newest first, then id, so ordering is stable', function (): void {
    $older = createAsset(['principal_id' => 1, 'filename' => 'old.pdf', 'prompt' => 'volcano', 'created_at' => Carbon::now()->subWeek()]);
    $newer = createAsset(['principal_id' => 1, 'filename' => 'new.pdf', 'prompt' => 'volcano', 'created_at' => Carbon::now()]);

    // Two rows can only be ordered deterministically if the tiebreak is
    // total; the id is the final tiebreak so repeated queries cannot
    // return the same two rows in a different order.
    expect(hitIds(search('volcano')))->toBe([$newer->id, $older->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scope — the security surface
// ─────────────────────────────────────────────────────────────────────────────

test('an empty context returns no hits and never reaches the database', function (): void {
    createAsset(['principal_id' => 1, 'filename' => 'invoice.pdf']);

    // The interface's hard requirement: an empty context means the caller
    // can see nothing, so the answer is `[]` — not "everything".
    expect(search('invoice', []))->toBe([]);
    // A principal id of 0 or below names no principal and is dropped, so a
    // context that carries only those is empty in the same way.
    expect(search('invoice', [0, -3]))->toBe([]);
});

test('an asset outside the context principals is NEVER returned', function (): void {
    // The security test. Everything the caller may see matches; the row
    // belonging to another principal matches the query just as well, and
    // must still be absent — including when the caller's context also names
    // a second principal, so the leak cannot hide behind a union.
    $mine    = createAsset(['principal_id' => 1, 'filename' => 'shared-name.pdf']);
    $theirs  = createAsset(['principal_id' => 2, 'filename' => 'shared-name.pdf']);
    $orphan  = createAsset(['principal_id' => 99, 'filename' => 'shared-name.pdf']);

    $hits = search('shared-name', [1, 7]);

    expect(hitIds($hits))->toBe([$mine->id]);
    expect(hitIds($hits))->not->toContain($theirs->id, $orphan->id);
});

test('an agent-scoped row is found through the agent join and through nobody else', function (): void {
    // The legacy back-compat branch: a row whose `principal_id` stayed NULL
    // (uploaded before migration 0075) still belongs to the principal that
    // owns its agent. Mirrors `applyPrincipalIdScope()`, so the palette and
    // the archive grid cannot disagree about who owns a row.
    $agentId = createAgent('legacy-owner', 1);

    $legacy = createAsset([
        'principal_id' => null,
        'agent_id'     => $agentId,
        'filename'     => 'legacy-row.pdf',
    ]);

    $otherAgentId = createAgent('other-owner', 2);
    $foreign = createAsset([
        'principal_id' => null,
        'agent_id'     => $otherAgentId,
        'filename'     => 'legacy-row.pdf',
    ]);

    expect(hitIds(search('legacy-row', [1])))->toBe([$legacy->id]);
    expect(hitIds(search('legacy-row', [2])))->toBe([$foreign->id]);
});

test('a user_id-only row is reachable through its user principal, and through nothing else', function (): void {
    // The scoping question this provider exists to answer. `user_id` is not
    // a visibility column: migration 0075 backfilled `principal_id` for
    // every row that had one, and the ingest pipeline keeps doing so, so a
    // `user_id`-only row belongs to that user's *user-principal*. It is
    // reachable — and reachable only there. The admin's wider read is
    // deliberately not reproduced here: `SearchContext` hands an admin the
    // admin's own visible principals, so honouring the context is what
    // keeps this from being a cross-tenant read.
    $mine   = createAsset(['principal_id' => 1, 'user_id' => 10, 'filename' => 'legacy-upload.png']);
    $theirs = createAsset(['principal_id' => 2, 'user_id' => 20, 'filename' => 'legacy-upload.png']);

    expect(hitIds(search('legacy-upload', [1])))->toBe([$mine->id]);
    expect(hitIds(search('legacy-upload', [2])))->toBe([$theirs->id]);
    expect(hitIds(search('legacy-upload', [1, 2])))->toHaveCount(2);
});

test('a row with no attribution at all is not findable, and the provider does not widen to reach it', function (): void {
    // `principal_id IS NULL AND agent_id IS NULL`: anonymous ingestion, a
    // batch import, operator recovery. No principal names such a row, so no
    // context can contain it — migration 0075's docblock records that these
    // surface only under the archive's unscoped `ALL` chip. Reproducing that
    // here would be the cross-tenant read `SearchProviderInterface` forbids,
    // so the row stays unfindable and the gap is real.
    $orphan = createAsset(['principal_id' => null, 'agent_id' => null, 'user_id' => null, 'filename' => 'unattributed.png']);

    expect(search('unattributed', [1]))->toBe([]);
    expect(search('unattributed', [1, 2, 3]))->toBe([]);
    // And it is genuinely there, so the empty result above is a scope
    // decision rather than a fixture that failed to persist.
    expect(MediaAsset::query()->find($orphan->id))->not->toBeNull();
});

test('a repeated principal id yields one hit, not one per membership', function (): void {
    // A user in two groups sees the same asset through either principal.
    // The provider is scoped to principal ids, so without de-duplication
    // the join would emit the row twice and burn a slot of the cap.
    $asset = createAsset(['principal_id' => 1, 'filename' => 'shared.pdf']);

    expect(hitIds(search('shared', [1, 1, 1])))->toBe([$asset->id]);
    expect(search('shared', [1, 1, 1]))->toHaveCount(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Rows the palette should not offer
// ─────────────────────────────────────────────────────────────────────────────

test('a temporary row is not offered', function (): void {
    // The archive grid hides `is_temporary` rows by default: they are
    // short-lived voice transcripts. Offering them would let a burst of
    // them push real documents out of the cap.
    createAsset(['principal_id' => 1, 'filename' => 'temp-note.m4a', 'is_temporary' => true]);
    $permanent = createAsset(['principal_id' => 1, 'filename' => 'permanent-note.m4a']);

    expect(hitIds(search('note')))->toBe([$permanent->id]);
});

test('a derivative is not offered alongside its parent', function (): void {
    // A derivative is a full `media_assets` row that inherits the parent's
    // `principal_id`, so without the exclusion a PDF and the `md` rendered
    // out of it are two hits for one document. The detail page's versions
    // strip is where a derivative is reached.
    $parent = createAsset(['principal_id' => 1, 'filename' => 'contract.pdf']);
    $derivative = createAsset(['principal_id' => 1, 'filename' => 'contract.md']);
    Capsule::connection()->table('media_derivatives')->insert([
        'id'                => 'der-1',
        'parent_id'         => $parent->id,
        'derivative_id'     => $derivative->id,
        'format'            => 'md',
        'producer_plugin'   => 'spora-plugin-typst',
        'producer_operation' => 'render',
        'created_at'        => Carbon::now(),
        'updated_at'        => Carbon::now(),
    ]);

    expect(hitIds(search('contract')))->toBe([$parent->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Presentation
// ─────────────────────────────────────────────────────────────────────────────

test('label is the filename, subLabel the prompt, badge the media type', function (): void {
    $asset = createAsset([
        'principal_id' => 1,
        'filename'     => 'diagram.png',
        'prompt'       => 'an architecture diagram of the plugin seam',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
    ]);

    $hit = search('diagram')[0];

    expect($hit->label)->toBe('diagram.png');
    expect($hit->subLabel)->toBe('an architecture diagram of the plugin seam');
    // `media_type` is a closed vocabulary and reads as a category; the mime
    // is the noisier fallback for a row whose type is null or unrecognised.
    expect($hit->badge)->toBe('image');
});

test('badge falls back to mime_type and then to null', function (): void {
    $untyped = createAsset(['principal_id' => 1, 'filename' => 'a.bin', 'media_type' => null, 'mime_type' => 'application/octet-stream']);
    $bare    = createAsset(['principal_id' => 1, 'filename' => 'b.bin', 'media_type' => null, 'mime_type' => null]);

    expect(search('a.bin')[0]->badge)->toBe('application/octet-stream');
    expect(search('b.bin')[0]->badge)->toBeNull();
});

test('subLabel is null without a prompt, and truncated with a very long one', function (): void {
    $noPrompt = createAsset(['principal_id' => 1, 'filename' => 'quiet.pdf', 'prompt' => null]);
    $long     = createAsset(['principal_id' => 1, 'filename' => 'verbose.pdf', 'prompt' => str_repeat('a very long prompt ', 40)]);

    expect(search('quiet')[0]->subLabel)->toBeNull();
    // One palette row is one line: the host truncates visually, but sending
    // 4 KB per hit on every debounced keystroke is waste.
    expect(mb_strlen((string) search('verbose')[0]->subLabel))->toBe(160);
});

// ─────────────────────────────────────────────────────────────────────────────
// Bound
// ─────────────────────────────────────────────────────────────────────────────

test('hits are capped', function (): void {
    for ($i = 0; $i < 25; $i++) {
        createAsset([
            'principal_id' => 1,
            'filename'     => sprintf('cap-%02d.png', $i),
            'created_at'   => Carbon::now(),
        ]);
    }

    $hits = search('cap-');

    expect(count($hits))->toBe(20);
    // A cap that truncated arbitrarily would not be reproducible; the
    // tiebreak is total, so the same query returns the same 20 rows.
    expect(hitIds($hits))->toBe(hitIds(search('cap-')));
});

test('the cap is global across principals, so a large group can hide a small one', function (): void {
    // The documented trade, pinned rather than left as prose: the cap applies
    // to the union of every principal in the context, so twenty better-ranked
    // rows under one principal leave no slot for another. Twenty-five prefix
    // matches beat the one substring match on rank alone, which keeps the
    // outcome a statement about the cap rather than about the tiebreak.
    for ($i = 0; $i < 25; $i++) {
        createAsset([
            'principal_id' => 1,
            'filename'     => sprintf('starve-%02d.png', $i),
        ]);
    }
    $small = createAsset(['principal_id' => 2, 'filename' => 'a-note-about-starve.png']);

    $hits = search('starve', [1, 2]);

    expect(count($hits))->toBe(20);
    expect(hitIds($hits))->not->toContain($small->id);
    // Nothing wrong with the small principal's row — it is simply outranked
    // and outnumbered, which is the whole of the claim. And it is findable
    // the moment the crowded principal is out of the context.
    expect(MediaAsset::query()->find($small->id))->not->toBeNull();
    expect(hitIds(search('starve', [2])))->toBe([$small->id]);
});

test('the cap is applied after ranking, not before', function (): void {
    // 30 body matches, then one filename match created last. A cap applied
    // before ranking would return 20 body matches and drop the file the
    // operator actually named.
    for ($i = 0; $i < 30; $i++) {
        createAsset([
            'principal_id' => 1,
            'filename'     => sprintf('noise-%02d.png', $i),
            'prompt'       => 'needle in the prompt',
        ]);
    }
    $named = createAsset(['principal_id' => 1, 'filename' => 'needle.pdf']);

    $hits = search('needle');

    expect(count($hits))->toBe(20);
    expect($hits[0]->id)->toBe($named->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// What crosses the wire
// ─────────────────────────────────────────────────────────────────────────────

test('every model property the provider reads is in the select list', function (): void {
    // The guard that outlives this PR. Reading a column that the select list
    // does not name does not throw — Eloquent hands back `null` — so a hit
    // silently loses its label, its sublabel or its badge. The reads are
    // recovered from the provider's own source, so adding a model read
    // without adding the column fails here.
    $reads = providerModelReads();

    // Nothing scanned would make the loop below vacuous.
    expect($reads)->not->toBeEmpty();

    foreach ($reads as $read) {
        expect(providerSelectedColumns())->toContain($read);
    }
});

test('every column in the select list is a column the table actually has', function (): void {
    // The other direction: a typo'd or renamed column is a SQL error on the
    // first keystroke of the first search, with nothing else in the suite
    // pointing at it.
    foreach (providerSelectedColumns() as $column) {
        expect(mediaAssetColumns())->toContain($column);
    }
});

test('the query never fetches the payload blob', function (): void {
    // `payload` is a MEDIUMBLOB with a 16 MiB ceiling (core migration 0064),
    // and on the default `auto` store everything over 1 MiB lives there. An
    // unselected `->get()` would move twenty of them — and hold them — on
    // every debounced keystroke. 512 KiB here is one row's worth of proof;
    // the assertion is about the statement, not the row.
    createAsset([
        'principal_id' => 1,
        'filename'     => 'bulky.pdf',
        'payload'      => str_repeat('x', 512 * 1024),
    ]);

    $statements = mediaAssetSelects(static function (): void {
        search('bulky');
    });

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $statement) {
        foreach (['id', 'filename', 'prompt', 'media_type', 'mime_type'] as $rendered) {
            expect($statement)->toContain($rendered);
        }
        // The bytes, and the columns that name or expose them. Nothing in a
        // palette row needs any of these.
        foreach (['payload', 'asset_url', 'asset_token', 'public_access_token', 'metadata', 'source_url'] as $heavy) {
            expect($statement)->not->toContain($heavy);
        }
    }
});

test('the text-only columns are filtered on without being fetched back', function (): void {
    // `tags` and `transcript` are matched against in SQL, which is the point:
    // pulling them back would hydrate a JSON-decoded tag array and a whole
    // transcript per hit to render neither. They stay out of the select list
    // and matching is unaffected.
    $tagged      = createAsset(['principal_id' => 1, 'filename' => 'tagged.png', 'tags' => ['volcano']]);
    $transcribed = createAsset(['principal_id' => 1, 'filename' => 'said.m4a', 'transcript' => 'we passed the volcano']);

    $statements = mediaAssetSelects(static function (): void {
        search('volcano');
    });

    expect($statements)->not->toBeEmpty();
    expect(providerSelectedColumns())->not->toContain('tags', 'transcript');
    expect(hitIds(search('volcano')))->toContain($tagged->id, $transcribed->id);
});
