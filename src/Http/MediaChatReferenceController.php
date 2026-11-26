<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\MediaArchive\MediaChatReferenceCounter;
use Spora\Services\MediaArchive\MediaArchiveService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * How many chats reference each of a set of Media Archive assets.
 *
 * The delete confirmation asks for this before it deletes: an asset a
 * chat renders disappears from that chat when the row goes, and the
 * operator is told how many conversations are affected while they can
 * still cancel. It is a warning, not a gate — the deletion itself is
 * unchanged.
 *
 * The request shape and its refusals mirror core's
 * {@see \Spora\Http\MediaResolveController}, which answers the same
 * "batch of archive uuids" question for the chat list: the same
 * `{ids: [...]}` body, the same 64-id cap, the same "every id must be a
 * UUID string" refusal.
 *
 * An asset the caller cannot see is reported as `0` rather than refused,
 * the way core's resolve endpoint hides a foreign id: the panel asks
 * about the assets it is about to delete, and a count it may not have is
 * a count it does not get.
 *
 * The answer carries exactly one key per requested id, spelled the way
 * the caller spelled it.
 */
final class MediaChatReferenceController
{
    use JsonControllerHelpers;

    /**
     * Hard cap on ids per request, the same bound core's resolve
     * endpoint uses: the ids come from a selection, and the count is a
     * single query over the chat history.
     */
    private const MAX_IDS_PER_REQUEST = 64;

    /**
     * A UUID, anchored at both ends.
     *
     * The end anchor is `\z` and not `$`: PCRE's `$` also matches
     * before a trailing newline, so `"…666\n"` would pass as a UUID
     * string and reach the counter as an id that names nothing.
     */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(
        private readonly MediaChatReferenceCounter $counter,
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
    ) {}

    public function chatReferences(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->error(
                'UNAUTHORIZED',
                'You must be logged in to count chat references.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $ids = $this->parseIds($request);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $counts = $this->counter->countFor($this->visibleIds($ids, $userId));
        foreach ($ids as $id) {
            $counts[$id] ??= 0;
        }

        return new JsonResponse(['data' => ['counts' => $counts]]);
    }

    /**
     * The requested ids the caller can see, in the spelling the caller
     * used.
     *
     * The id the row carries and the id the caller asked for can differ
     * in case — `media_assets.id` is a `char(36)` under a
     * case-insensitive collation on MySQL — and the count is the same
     * either way, so the lookup folds case and the answer is keyed by
     * the requested id. Keying it by the stored id would answer one
     * request under two keys, one of them reading `0` beside the real
     * count.
     *
     * @param  list<string> $ids
     * @return list<string>
     */
    private function visibleIds(array $ids, int $userId): array
    {
        $folded = [];
        foreach ($ids as $id) {
            $folded[mb_strtolower($id)] = true;
        }

        $assets = $this->mediaArchive->resolveMany(array_keys($folded), $userId, $this->auth->isAdmin());

        $visible = [];
        foreach ($assets as $asset) {
            $visible[mb_strtolower($asset->id)] = true;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($visible[mb_strtolower($id)])) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * Parse and validate the `ids` body field. Returns the validated list
     * on success, or a 422 `JsonResponse` on any validation failure.
     *
     * @return list<string>|JsonResponse
     */
    private function parseIds(Request $request): array|JsonResponse
    {
        $body = $this->decodeJsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $ids = $this->extractIdList($body);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $checked = $this->validateUuidList($ids);

        return $checked instanceof JsonResponse ? $checked : array_values($checked);
    }

    /**
     * Decode the request body as JSON. Returns a 422 `JsonResponse` on
     * parse failure or a non-object body; the decoded value otherwise.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->validationError('Request body must be valid JSON.');
        }

        if (!is_array($body) || !array_key_exists('ids', $body)) {
            return $this->validationError('Body must include an "ids" array.');
        }

        return $body;
    }

    /**
     * Pull the `ids` field out of the decoded body and enforce the
     * presence and cap constraints.
     *
     * @param  array<string, mixed> $body
     * @return list<mixed>|JsonResponse
     */
    private function extractIdList(array $body): array|JsonResponse
    {
        $raw = $body['ids'];
        if (!is_array($raw) || $raw === []) {
            return $this->validationError('"ids" must be a non-empty array.');
        }
        if (count($raw) > self::MAX_IDS_PER_REQUEST) {
            return $this->validationError(sprintf('At most %d ids per request.', self::MAX_IDS_PER_REQUEST));
        }

        return array_values($raw);
    }

    /**
     * Walk the raw id list, dropping duplicates (preserving the first
     * occurrence) and verifying each entry is a UUID.
     *
     * @param  list<mixed> $raw
     * @return array<string, string>|JsonResponse
     */
    private function validateUuidList(array $raw): array|JsonResponse
    {
        $ids = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || preg_match(self::UUID_REGEX, $entry) !== 1) {
                return $this->validationError('Every id must be a UUID string.');
            }
            if (!array_key_exists($entry, $ids)) {
                $ids[$entry] = $entry;
            }
        }

        return $ids;
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->error('VALIDATION_ERROR', $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
