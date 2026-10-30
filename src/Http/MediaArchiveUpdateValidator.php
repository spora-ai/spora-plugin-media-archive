<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Http;

use Spora\Services\MediaArchive\MediaAssetKind;

/**
 * PATCH /api/v1/media/{id} field validators.
 *
 * Split out of the controller so the patch surface keeps one method per
 * field — the SonarCloud S1448 rule caps a class at a fixed number of
 * methods. Each method returns the first error string it finds for the
 * field, or null when the payload passes.
 *
 * `null` is the "absent field" sentinel for most fields: the controller
 * treats an absent field and a present-but-valid one alike. `folder_id`
 * is the exception — `null` there means the root, and it is a write.
 */
final class MediaArchiveUpdateValidator
{
    /**
     * `filename` accepts `null` (clears) or a string of up to 255
     * characters. Longer strings are rejected to match the column width and
     * keep the listing-render from clipping in the UI.
     *
     * @param array<string, mixed> $body
     */
    public static function validateFilename(array $body): ?string
    {
        if (!array_key_exists('filename', $body)) {
            return null;
        }
        $filename = $body['filename'];
        if ($filename !== null && (!is_string($filename) || strlen($filename) > 255)) {
            return 'filename must be a string up to 255 characters.';
        }
        return null;
    }

    /**
     * Shared validator for `tags` and `metadata`: both reject non-null
     * non-array payloads. The error message is caller-supplied so the
     * field name surfaces in the response envelope.
     *
     * @param array<string, mixed> $body
     */
    public static function validateArray(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_array($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * Nullable string field on the patch surface (`prompt`): rejects
     * non-null non-string payloads and accepts `null` (clears). The error
     * message is caller-supplied for the same reason as
     * {@see validateArray} — the field name surfaces in the response
     * envelope.
     *
     * A field that is no longer on the contract is not routed here at all,
     * which is why an unknown key is a silent no-op rather than a 400: this
     * only runs for names the caller still recognises.
     *
     * @param array<string, mixed> $body
     */
    public static function validateString(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_string($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * `public_access_enabled` is the only boolean field on the patch
     * surface. A non-boolean here is always an error (no `null` semantic —
     * the toggle is on or off, never cleared).
     *
     * @param array<string, mixed> $body
     */
    public static function validateBool(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        if (!is_bool($body[$field])) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * `folder_id` names the folder the asset is filed in; `null` files it
     * back into the root.
     *
     * Only the shape is checked here. Whether the folder exists, whose
     * it is and whether the name is free in it belong to core's folder
     * service, which raises them as domain errors the controller turns
     * into a 422.
     *
     * @param array<string, mixed> $body
     */
    public static function validateFolderId(array $body): ?string
    {
        if (!array_key_exists('folder_id', $body)) {
            return null;
        }
        $value = $body['folder_id'];
        if ($value !== null && !is_string($value)) {
            return 'folder_id must be a string or null.';
        }
        return null;
    }

    /**
     * `kind` is the advisory mark, and the vocabulary is closed on a
     * write: the listing filter drops an unknown mark silently, and
     * storing a mark the operator did not ask for is worse than
     * refusing the write.
     *
     * @param array<string, mixed> $body
     */
    public static function validateKind(array $body): ?string
    {
        if (!array_key_exists('kind', $body)) {
            return null;
        }
        $value = $body['kind'];
        if (!is_string($value) || !in_array($value, MediaAssetKind::ALL, true)) {
            return 'kind must be one of: ' . implode(', ', MediaAssetKind::ALL) . '.';
        }
        return null;
    }
}
