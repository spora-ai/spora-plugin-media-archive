<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plugin-owned admin REST surface for the Media Archive rows that the
 * operator dashboard edits: single-asset detail, PATCH (metadata, folder,
 * kind), DELETE and rotating the public-access token. The list endpoint
 * stays in {@see \Spora\Http\MediaArchiveController::index} because the
 * composer and upload UI call it too.
 *
 * Auth is enforced by the route's middleware (AuthMiddleware +
 * CsrfMiddleware); the controller does not duplicate the check. PATCH,
 * DELETE and refreshPublicToken additionally gate behind canEdit() so a
 * non-admin caller can only touch their own rows.
 */
final class MediaArchiveAdminController
{
    use JsonControllerHelpers;

    private const MSG_NOT_FOUND  = 'Media asset not found.';
    private const MSG_FORBIDDEN  = 'You do not own this media asset.';

    /**
     * A folder the caller may not file into is reported as a folder that
     * does not exist: the caller has no business learning that a folder
     * of another tenant is there.
     */
    private const MSG_UNKNOWN_FOLDER = 'That folder does not exist for this asset.';

    /**
     * An empty `folder_id` names no folder; the root is `null`.
     */
    private const MSG_EMPTY_FOLDER = 'An empty folder_id names no folder. Send a folder id, or null to file the asset into the root.';

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly MediaFolderService $folders,
        // PHP-DI injects a real instance in production — the plugin's own
        // container definition force-injects it — so the nullable default
        // only applies to tests.
        private readonly ?MediaDerivativeService $derivatives = null,
        private readonly MediaAssetSerializer $defaultSerializer = new MediaAssetSerializer(),
        private readonly array $config = [],
    ) {}

    public function show(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', self::MSG_NOT_FOUND);
        }

        return new JsonResponse(['data' => $this->serializer()->serialize($asset)]);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $editable = $this->findEditableAsset($id);
        if ($editable instanceof JsonResponse) {
            return $editable;
        }

        $body = $this->jsonBody($request);
        $validation = $this->validateUpdatableFields($body);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        $dirty = $this->extractUpdatableFields($body);
        $filing = array_key_exists('folder_id', $dirty);
        $folderId = $filing && is_string($dirty['folder_id']) ? $dirty['folder_id'] : null;
        unset($dirty['folder_id']);

        if ($dirty !== []) {
            $editable->fill(Utf8Sanitizer::scrub($dirty));
        }

        try {
            $this->write($editable, $filing, $folderId, $dirty);
        } catch (MediaFolderNotFoundException) {
            return $this->unprocessable('UNKNOWN_FOLDER', self::MSG_UNKNOWN_FOLDER);
        } catch (MediaFolderUniquenessException $e) {
            return $this->unprocessable('FOLDER_FILENAME_TAKEN', $e->getMessage());
        }

        return new JsonResponse(['data' => $this->serializer()->serialize($editable, $this->configUrl())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $editable = $this->findEditableAsset($id);
        if ($editable instanceof JsonResponse) {
            return $editable;
        }

        $this->mediaArchive->delete($id);

        return new JsonResponse(['data' => ['deleted' => true, 'id' => $id]]);
    }

    public function refreshPublicToken(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', self::MSG_NOT_FOUND);
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden('FORBIDDEN', self::MSG_FORBIDDEN);
        }
        $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
        $asset->save();
        return new JsonResponse(['data' => $this->serializer()->serialize($asset, $this->configUrl())]);
    }

    /**
     * Build a serializer that loads persisted derivatives when the
     * service was wired in. Without this swap the detail page always
     * sees `derivatives: []` on reload — `onDerivativeProduced()` only
     * covers the in-memory splice, a hard refresh re-fetches
     * `GET /api/v1/media/{id}`.
     */
    private function serializer(): MediaAssetSerializer
    {
        return $this->derivatives !== null
            ? new MediaAssetSerializer(true, $this->derivatives)
            : $this->defaultSerializer;
    }

    private function findEditableAsset(string $id): MediaAsset|JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', self::MSG_NOT_FOUND);
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden('FORBIDDEN', self::MSG_FORBIDDEN);
        }

        return $asset;
    }

    private function canEdit(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }
        $userId = $this->auth->currentUserId();
        return $userId !== null && $asset->user_id !== null && (int) $asset->user_id === $userId;
    }

    /**
     * Persist the patched fields.
     *
     * The folder write goes through core's folder service: the UNIQUE
     * `(folder_id, filename)` index is a domain rule the service owns, and
     * a `fill()` would land on the raw index and raise a driver-level
     * error instead of a message the operator can act on.
     *
     * @param array<string, mixed> $dirty
     */
    private function write(MediaAsset $asset, bool $filing, ?string $folderId, array $dirty): void
    {
        if ($filing) {
            $this->folders->fileAsset($this->principalId($asset), $asset, $folderId);
            return;
        }

        // A rename inside a folder is bound by the same filename rule, so
        // it goes through the service too — re-filed into the folder the
        // asset already sits in.
        if (array_key_exists('filename', $dirty) && $asset->folder_id !== null) {
            $this->folders->fileAsset($this->principalId($asset), $asset, (string) $asset->folder_id);
            return;
        }

        $asset->save();
    }

    /**
     * The principal a folder lookup is scoped to.
     *
     * An asset with no principal belongs to no tenant, so no folder is
     * fileable into it: the lookup runs against principal 0, which owns
     * no folder, and the folder is reported as unknown.
     */
    private function principalId(MediaAsset $asset): int
    {
        return (int) ($asset->principal_id ?? 0);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractUpdatableFields(array $body): array
    {
        $dirty = [];
        // The list is an allowlist and the only gate on the write path: a
        // key it does not name never reaches the write, so a stale client
        // that still PATCHes it gets a silent no-op and the rest of the
        // body still applies. `markdown_content` left the list when
        // document extraction moved to `md` derivatives in spora-core.
        //
        // The ordering runs both ways. A key may be dropped here before
        // core drops its column — against a core that still has the
        // column that is a silent capability loss, not a break; the
        // direction that does break is core dropping the column while
        // this allowlist still names the key, which writes to a missing
        // column on every PATCH that carries one. A key may be *named*
        // here only once core has the column, so `folder_id` and `kind`
        // follow core's folders release.
        foreach (['filename', 'tags', 'metadata', 'prompt', 'kind'] as $field) {
            if (array_key_exists($field, $body)) {
                $dirty[$field] = $body[$field];
            }
        }
        if (array_key_exists('folder_id', $body)) {
            // The folder is written by core's folder service, not by
            // `fill()`: the filename rule inside a folder is the
            // service's, and it raises it as a domain error.
            $dirty['folder_id'] = $body['folder_id'];
        }
        if (array_key_exists('public_access_enabled', $body)) {
            $enabled = $body['public_access_enabled'];
            $dirty['public_access_token'] = $enabled === true ? MediaArchiveService::mintPublicAccessToken() : null;
        }
        return $dirty;
    }

    /**
     * Run each per-field validator in order; the first one that produces
     * an error message short-circuits the response. The per-field checks
     * live in {@see MediaArchiveUpdateValidator}, so this stays a flat list
     * of calls.
     *
     * A malformed value is a 400 like every other field on the patch
     * surface; a value the server reads but will not store — a kind
     * outside the vocabulary, a folder that does not exist — is a 422.
     *
     * @param array<string, mixed> $body
     */
    private function validateUpdatableFields(array $body): ?JsonResponse
    {
        $messages = [
            MediaArchiveUpdateValidator::validateFilename($body),
            MediaArchiveUpdateValidator::validateArray($body, 'tags', 'tags must be an array of strings.'),
            MediaArchiveUpdateValidator::validateArray($body, 'metadata', 'metadata must be an object.'),
            MediaArchiveUpdateValidator::validateString($body, 'prompt', 'prompt must be a string.'),
            MediaArchiveUpdateValidator::validateBool($body, 'public_access_enabled', 'public_access_enabled must be a boolean.'),
            MediaArchiveUpdateValidator::validateFolderId($body),
        ];
        foreach ($messages as $message) {
            if ($message !== null) {
                return $this->badRequest($message);
            }
        }

        // Core's folder service reads an empty string as the root, so the
        // empty string is refused here rather than by `validateFolderId()`:
        // `null` is the root, and a write the caller never asked for is
        // worse than a refusal.
        if (($body['folder_id'] ?? null) === '') {
            return $this->unprocessable('UNKNOWN_FOLDER', self::MSG_EMPTY_FOLDER);
        }

        $kind = MediaArchiveUpdateValidator::validateKind($body);
        if ($kind !== null) {
            return $this->unprocessable('INVALID_KIND', $kind);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve the public base URL from the global config. The
     * controller-scope `Request` is intentionally not used — share URLs
     * must be stable across requests and trace back to the operator's
     * configured origin (`config.app_url`), not the host a single
     * request happened to land on.
     */
    private function configUrl(): string
    {
        return (string) ($this->config['app_url'] ?? '');
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'BAD_REQUEST', 'message' => $message]],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
