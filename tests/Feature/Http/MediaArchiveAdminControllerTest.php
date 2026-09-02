<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Feature\Http;

use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController;
use Spora\Plugins\MediaArchive\Tests\Support\MediaArchiveTestSupport;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpFoundation\Request;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

// ─────────────────────────────────────────────────────────────────────────────
// update() — PATCH /api/v1/media/{id}
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Plan §12 B2b — PATCH /api/v1/media/{id} coverage for the moved
 * `update()` method (formerly core, now in the plugin).
 */
test('PATCH filename is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'new.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['filename'])->toBe('new.txt');
});

test('PATCH tags is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['tags' => ['a', 'b']]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['tags'])->toBe(['a', 'b']);
});

test('PATCH metadata is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['metadata' => ['author' => 'me']]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['metadata'])->toBe(['author' => 'me']);
});

test('PATCH prompt is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['prompt' => 'updated']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['prompt'])->toBe('updated');
});

test('PATCH markdown_content is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['markdown_content' => "# Hello\n\nWorld"]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['markdown_content'])->toBe("# Hello\n\nWorld");
});

test('PATCH markdown_content can be cleared by sending null', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $asset->markdown_content = 'pre-existing';
    $asset->save();
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['markdown_content' => null]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['markdown_content'])->toBeNull();
});

test('PATCH markdown_content rejects non-string non-null payloads with 400', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['markdown_content' => ['not', 'a', 'string']]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['message'])->toBe('markdown_content must be a string.');
});

test('PATCH returns 403 when the asset is owned by a different non-admin user', function (): void {
    [, $service] = buildUpdateController();
    $asset = ingestSample($service, 99);
    [, , $controller] = buildUpdateController(false, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'no.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(403);
});

test('PATCH returns 200 for an admin even when owned by another user', function (): void {
    [, $service] = buildUpdateController(false, 99);
    $asset = ingestSample($service, 99);
    [, , $controller] = buildUpdateController(true, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'admin.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// show() — GET /api/v1/media/{id}
// ─────────────────────────────────────────────────────────────────────────────

test('GET /api/v1/media/{id} returns the single asset on show', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $resp = $controller->show($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['id'])->toBe($asset->id);
});

test('show returns 404 for unknown id', function (): void {
    [, , $controller] = buildUpdateController();
    $resp = $controller->show('00000000-0000-0000-0000-000000000000');
    expect($resp->getStatusCode())->toBe(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// destroy() — DELETE /api/v1/media/{id}
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ownership enforcement for DELETE /api/v1/media/{id}.
 *
 * destroy() previously accepted any authenticated caller and deleted the
 * row without checking ownership. update() and refreshPublicToken() both
 * gate behind canEdit(); this test pins the same gate on destroy().
 */
test('DELETE returns 403 when the asset is owned by a different user', function (): void {
    [$controller, $service] = buildDestroyFixtures(false, 1);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'secret',
        mime: 'text/plain',
        filename: 'secret.txt',
        userId: 99,
        uploadSource: 'upload',
    ));

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(403);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->not->toBeNull();
});

test('DELETE returns 200 for the owner', function (): void {
    [$controller, $service] = buildDestroyFixtures(false, 7);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'mine',
        mime: 'text/plain',
        filename: 'mine.txt',
        userId: 7,
        uploadSource: 'upload',
    ));

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['deleted'])->toBeTrue();
    expect($payload['data']['id'])->toBe($asset->id);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

test('DELETE returns 200 for an admin even when the asset is owned by another user', function (): void {
    [, $service] = buildDestroyFixtures(false, 7);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'someone-elses',
        mime: 'text/plain',
        filename: 'elses.txt',
        userId: 99,
        uploadSource: 'upload',
    ));

    [$controller] = buildDestroyFixtures(true, 1);

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

test('DELETE returns 404 when the asset does not exist', function (): void {
    [$controller] = buildDestroyFixtures(true, 1);
    $resp = $controller->destroy('00000000-0000-0000-0000-000000000000');
    expect($resp->getStatusCode())->toBe(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// refreshPublicToken() — POST /api/v1/media/{id}/public-token/refresh
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Plan §12 B2b — public-access token lifecycle on the moved methods.
 */
test('PATCH public_access_enabled:true mints a token and returns public_url', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['public_access_token'])->not->toBeNull();
    expect($body['data']['public_url'])->toContain('token=' . $body['data']['public_access_token']);
});

test('PATCH public_access_enabled:false clears the token', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $controller->update($asset->id, $req);
    $req2 = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => false]));
    $req2->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req2);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['public_access_token'])->toBeNull();
    expect($body['data']['public_url'])->toBeNull();
});

test('POST /api/v1/media/{id}/public-token/refresh rotates the token', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $controller->update($asset->id, $req);
    $first = \Spora\Models\MediaAsset::query()->find($asset->id)->public_access_token;
    expect($first)->not->toBeNull();

    $resp = $controller->refreshPublicToken($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    $second = \Spora\Models\MediaAsset::query()->find($asset->id)->public_access_token;
    expect($second)->not->toBe($first);
});

test('refresh is forbidden for non-owner non-admin', function (): void {
    // Asset is owned by user 99; request comes from user 1 (non-admin).
    [, $service] = buildSharingController();
    $asset = ingestSharedSample(99);
    // Re-fetch the controller with a non-admin caller (user 1).
    [, , $controller] = buildSharingController(false, 1);
    $resp = $controller->refreshPublicToken($asset->id);
    expect($resp->getStatusCode())->toBe(403);
});

test('refreshPublicToken returns 404 for unknown id', function (): void {
    [, , $controller] = buildSharingController();
    $resp = $controller->refreshPublicToken('00000000-0000-0000-0000-000000000000');
    expect($resp->getStatusCode())->toBe(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// update() validation surface (extracted MediaArchiveUpdateValidator coverage)
// ─────────────────────────────────────────────────────────────────────────────

test('update rejects non-string filename with 400', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['filename' => 12345]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('update rejects non-array tags with 400', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['tags' => 'not-an-array']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('update rejects non-bool public_access_enabled with 400', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => 'yes']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('update persists tags and prompt together', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['tags' => ['draft', 'redacted'], 'prompt' => 'updated prompt']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['tags'])->toBe(['draft', 'redacted']);
    expect($payload['data']['prompt'])->toBe('updated prompt');
});

test('update returns 404 for unknown id', function (): void {
    [, , $controller] = buildUpdateController();
    $req = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000', 'PATCH', content: '{}');
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update('00000000-0000-0000-0000-000000000000', $req);
    expect($resp->getStatusCode())->toBe(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{0: MediaArchiveService, 1: MediaArchiveService, 2: MediaArchiveAdminController}
 */
function buildUpdateController(bool $isAdmin = true, int $userId = 1): array
{
    $tmp = sys_get_temp_dir() . '/spora-update-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $auth = stubAuth($userId, $isAdmin);
    $controller = new MediaArchiveAdminController($service, $auth);
    return [$service, $service, $controller];
}

function ingestSample(MediaArchiveService $service, int $userId): \Spora\Models\MediaAsset
{
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: $userId,
        uploadSource: 'upload',
    ));
}

/**
 * @return array{0: MediaArchiveAdminController, 1: MediaArchiveService}
 */
function buildDestroyFixtures(bool $isAdmin, int $userId): array
{
    $tmp = sys_get_temp_dir() . '/spora-destroy-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service = MediaArchiveTestSupport::buildService($assetStore);
    return [
        new MediaArchiveAdminController($service, stubAuth($userId, $isAdmin)),
        $service,
    ];
}

/**
 * @return array{0: MediaArchiveService, 1: MediaArchiveService, 2: MediaArchiveAdminController}
 */
function buildSharingController(bool $isAdmin = true, int $userId = 1): array
{
    $tmp = sys_get_temp_dir() . '/spora-sharing-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    return [
        $service,
        $service,
        new MediaArchiveAdminController(
            $service,
            stubAuth($userId, $isAdmin),
            new MediaAssetSerializer(),
            ['app_url' => 'https://test.example/'],
        ),
    ];
}

function ingestSharedSample(int $userId = 1): \Spora\Models\MediaAsset
{
    [$service] = buildSharingController();
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: $userId,
        uploadSource: 'upload',
    ));
}

function stubAuth(int $userId, bool $isAdmin): AuthService
{
    // The base {@see AuthService} ctor takes a delight-im `Auth`
    // instance we don't need here; Mockery bypasses the constructor so
    // the controller can call `currentUserId()` / `isAdmin()` without
    // a real users table.
    $auth = M::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);
    $auth->shouldReceive('isAdmin')->andReturn($isAdmin);

    return $auth;
}
