<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plugin-owned admin REST surface for the Media Archive rows that the
 * operator dashboard edits (single-asset detail, PATCH metadata, DELETE,
 * and rotating the public-access token). The list endpoint stays in
 * {@see \Spora\Http\MediaArchiveController::index} because the composer
 * + upload UI also call it; the four routes below move here so the
 * plugin owns its CRUD end-to-end, mirroring the `spora-plugin-memories`
 * pattern.
 *
 * Auth is enforced by the route's middleware (AuthMiddleware +
 * CsrfMiddleware); the controller does not duplicate the check. PATCH,
 * DELETE, and refreshPublicToken additionally gate behind canEdit()
 * so a non-admin caller can only touch their own rows.
 *
 * The four methods were lifted verbatim from spora-core PR #221 (the
 * `e93495d` diff that trimmed MediaArchiveController to `index()` only)
 * — only the FQCN and namespace changed.
 */
final class MediaArchiveAdminController
{
    use JsonControllerHelpers;

    private const MSG_NOT_FOUND  = 'Media asset not found.';
    private const MSG_FORBIDDEN  = 'You do not own this media asset.';

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        // PHP-DI autowires a real instance in production; tests pass
        // null and rely on the explicit `$serializer` arg instead.
        private readonly ?MediaDerivativeService $derivatives = null,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
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
        if ($dirty !== []) {
            $editable->fill(Utf8Sanitizer::scrub($dirty));
            $editable->save();
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

    /**
     * Rotate the public-access token on a media row.
     *
     * POST /api/v1/media/{id}/public-token/refresh
     */
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
            : $this->serializer;
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
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractUpdatableFields(array $body): array
    {
        $dirty = [];
        foreach (['filename', 'tags', 'metadata', 'prompt', 'markdown_content'] as $field) {
            if (array_key_exists($field, $body)) {
                $dirty[$field] = $body[$field];
            }
        }
        if (array_key_exists('public_access_enabled', $body)) {
            $enabled = $body['public_access_enabled'];
            $dirty['public_access_token'] = $enabled === true ? MediaArchiveService::mintPublicAccessToken() : null;
        }
        return $dirty;
    }

    /**
     * Run each per-field validator in order; the first one that produces
     * an error message short-circuits the response. Per-field checks live
     * in their own helpers so the orchestrator stays under SonarQube's
     * 15 cognitive-complexity threshold — each helper is a free method
     * call from this scope.
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
            MediaArchiveUpdateValidator::validateString($body, 'markdown_content', 'markdown_content must be a string.'),
            MediaArchiveUpdateValidator::validateBool($body, 'public_access_enabled', 'public_access_enabled must be a boolean.'),
        ];
        foreach ($messages as $message) {
            if ($message !== null) {
                return $this->badRequest($message);
            }
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
