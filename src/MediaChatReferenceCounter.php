<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use Illuminate\Database\Eloquent\Builder;
use Spora\Models\TaskHistory;

/**
 * Counts the chats that reference a media asset.
 *
 * A chat references an asset through `task_history.attachments` — a JSON
 * array of `{media_id, kind}` objects (core migration 0057). The rows are
 * narrowed with a LIKE on the uuid and then matched exactly in PHP against
 * the cast array, so the number is never inflated by a pattern hit: the
 * LIKE is a cheap prefilter and the exact comparison is what makes the
 * count authoritative.
 *
 * A LIKE rather than a JSON path query is the right tool here: the
 * attachments sit in an array, so `json_extract` would need one path per
 * element index, and the element count is not known in advance. The uuid
 * is 36 characters of hex and dashes, so it cannot be a substring of any
 * other id — the prefilter cannot pull in a row that the exact pass would
 * not accept. The column is compared as text on both engines the plugin
 * runs on: SQLite stores a `json` column as TEXT, and MySQL compares a
 * JSON value with LIKE in its serialised form.
 *
 * The count is of DISTINCT `task_id`s. A chat holds one `task_history`
 * row per turn, and an asset attached to three turns of one chat is one
 * chat — which is the number the operator reads as "how many
 * conversations lose this image".
 */
final class MediaChatReferenceCounter
{
    /**
     * How many chats reference each of the given assets.
     *
     * Every id is present in the result, `0` when nothing references it.
     *
     * @param  list<string> $ids Media Archive uuids.
     * @return array<string, int>
     */
    public function countFor(array $ids): array
    {
        $counts = [];
        // The requested id and the one the rows carry can differ in
        // case, and both engines' LIKE folds case — so the comparison
        // downstream folds it too, rather than reporting a count the
        // prefilter found and the exact pass then threw away.
        $wanted = [];
        foreach ($ids as $id) {
            $counts[$id] = 0;
            $wanted[mb_strtolower($id)] = $id;
        }

        if ($wanted === []) {
            return $counts;
        }

        foreach ($this->chatsByAsset($wanted) as $key => $taskIds) {
            $counts[$wanted[$key]] = count($taskIds);
        }

        return $counts;
    }

    /**
     * The distinct task ids that reference each requested asset.
     *
     * @param  array<string, string> $wanted Lowercased id => the id as requested.
     * @return array<string, array<int, true>>
     */
    private function chatsByAsset(array $wanted): array
    {
        $rows = TaskHistory::query()
            ->select(['task_id', 'attachments'])
            ->whereNotNull('attachments')
            ->where(static function (Builder $query) use ($wanted): void {
                foreach (array_keys($wanted) as $needle) {
                    $query->orWhere('attachments', 'LIKE', '%' . $needle . '%');
                }
            })
            ->get();

        $chats = [];
        foreach ($rows as $row) {
            foreach ($this->mediaIds($row->attachments) as $mediaId) {
                $key = mb_strtolower($mediaId);
                if (!array_key_exists($key, $wanted)) {
                    continue;
                }
                $chats[$key][(int) $row->task_id] = true;
            }
        }

        return $chats;
    }

    /**
     * The `media_id`s of one history row's attachments.
     *
     * A row written by an older core may carry a shape this panel does
     * not expect, so every entry is checked rather than assumed.
     *
     * @return list<string>
     */
    private function mediaIds(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $ids = [];
        foreach ($attachments as $attachment) {
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
