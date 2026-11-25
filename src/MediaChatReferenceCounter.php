<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use Illuminate\Database\Eloquent\Builder;
use JsonException;
use Spora\Models\TaskHistory;

/**
 * Counts the chats that reference a media asset.
 *
 * A chat references an asset through `task_history.attachments` — a JSON
 * array of `{media_id, kind}` objects (core migration 0057). The rows are
 * narrowed with a LIKE on the uuid and then matched in PHP against the
 * decoded attachments, so the number is never inflated by a pattern
 * hit: the LIKE is a cheap prefilter and the PHP comparison is what
 * makes the count authoritative.
 *
 * A LIKE rather than a JSON path query is the right tool here: the
 * attachments sit in an array, so `json_extract` would need one path per
 * element index, and the element count is not known in advance. The uuid
 * is 36 characters of hex and dashes, so it cannot be a substring of
 * another uuid — the prefilter cannot match a row that references a
 * different asset. It can match a row that mentions the uuid in some
 * other field of its JSON, and the exact pass is what settles that.
 *
 * The comparison folds case on both sides, and the fold has to happen in
 * SQL as well as in PHP because the two engines disagree about LIKE on
 * this column. SQLite stores `attachments` as TEXT and folds ASCII case
 * in LIKE; MySQL stores it as a native JSON column and compares the
 * serialised document byte by byte under a binary collation, so a LIKE
 * there is case-sensitive. Folding the column in SQL keeps the prefilter
 * a superset of the exact pass on either engine, which is what makes the
 * count the same number on either engine.
 *
 * The count is of DISTINCT `task_id`s. A chat holds one `task_history`
 * row per turn, and an asset attached to three turns of one chat is one
 * chat — which is the number the operator reads as "how many
 * conversations lose this image".
 *
 * The scan costs a full pass over `task_history`: nothing indexes
 * `attachments`, and on MySQL each of the LIKE arms serialises the JSON
 * document of every row it reads. That is affordable for an operator
 * action behind a confirmation dialog, and the request's id cap bounds
 * it. Were the count ever to become a hot path, the reference would move
 * into core as a generated column or a join table rather than the plugin
 * growing an index of its own.
 */
final class MediaChatReferenceCounter
{
    /**
     * The `attachments` column read as text and folded to lower case.
     *
     * MySQL's `attachments` is a native JSON column whose LIKE
     * comparison is byte-for-byte on the serialised document, so the
     * case has to be folded in SQL for the prefilter to stay a superset
     * of the exact pass. `CAST(... AS CHAR)` is the documented way to
     * read a JSON document as text on MySQL, and SQLite accepts it for
     * its TEXT column unchanged.
     */
    private const FOLDED_ATTACHMENTS = 'lower(CAST(attachments AS CHAR))';

    /**
     * How many chats reference each of the given assets.
     *
     * Every id is present in the result, `0` when nothing references it.
     * Two ids that differ only in case name the same asset and share
     * one count.
     *
     * @param  list<string> $ids Media Archive uuids.
     * @return array<string, int>
     */
    public function countFor(array $ids): array
    {
        $counts = [];
        $wanted = [];
        foreach ($ids as $id) {
            $counts[$id] = 0;
            $wanted[mb_strtolower($id)][] = $id;
        }

        if ($wanted === []) {
            return $counts;
        }

        foreach ($this->chatsByAsset($wanted) as $folded => $taskIds) {
            $count = count($taskIds);
            foreach ($wanted[$folded] as $id) {
                $counts[$id] = $count;
            }
        }

        return $counts;
    }

    /**
     * The distinct task ids that reference each requested asset.
     *
     * @param  array<string, list<string>> $wanted Folded id => the ids as requested.
     * @return array<string, array<int, true>>
     */
    private function chatsByAsset(array $wanted): array
    {
        $rows = $this->query($wanted)->get();

        $chats = [];
        foreach ($rows as $row) {
            foreach ($this->mediaIds($row->getRawOriginal('attachments')) as $mediaId) {
                $folded = mb_strtolower($mediaId);
                if (!array_key_exists($folded, $wanted)) {
                    continue;
                }
                $chats[$folded][(int) $row->task_id] = true;
            }
        }

        return $chats;
    }

    /**
     * The rows that can carry a reference to one of the requested assets.
     *
     * @param  array<string, list<string>> $wanted Folded id => the ids as requested.
     * @return Builder<TaskHistory>
     */
    private function query(array $wanted): Builder
    {
        $builder = TaskHistory::query();
        $builder->select(['task_id', 'attachments']);
        $builder->whereNotNull('attachments');
        $builder->where(static function (Builder $query) use ($wanted): void {
            foreach (array_keys($wanted) as $needle) {
                $query->orWhereRaw(self::FOLDED_ATTACHMENTS . ' LIKE ?', ['%' . $needle . '%']);
            }
        });

        return $builder;
    }

    /**
     * The `media_id`s of one history row's attachments.
     *
     * The column is decoded here rather than through the model's `array`
     * cast, so that a row holding something that is not JSON at all
     * reads as "no references" instead of depending on how the ORM
     * treats a failed decode. SQLite stores the column as unvalidated
     * TEXT, so such a row can exist.
     *
     * @return list<string>
     */
    private function mediaIds(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $mediaId = $attachment['media_id'] ?? null;
            if (is_string($mediaId)) {
                $ids[] = $mediaId;
            }
        }

        return $ids;
    }
}
