<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController;
use Spora\Plugins\MediaArchive\Tests\Support\MediaArchiveTestSupport;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /api/v1/media/{id} — the folder and the kind.
 *
 * The folder write belongs to core's folder service: it owns the tenant
 * boundary and the filename rule inside a folder, and it raises them as
 * domain errors. The plugin's job is to pass the folder through, to
 * refuse a mark outside the vocabulary, and to turn the service's errors
 * into a 422 the operator can act on.
 */
test('PATCH files the asset into a folder of its own principal', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);
    $folder = filingFolderService()->create($principalId, 'Corporate');

    $resp = patchAsset($controller, $asset->id, ['folder_id' => (string) $folder->id]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['folder_id'])->toBe((string) $folder->id);
    expect(MediaAsset::query()->findOrFail($asset->id)->folder_id)->toBe((string) $folder->id);
});

test('PATCH folder_id null files the asset back into the root', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);
    $folder = filingFolderService()->create($principalId, 'Corporate');
    filingFolderService()->fileAsset($principalId, $asset, (string) $folder->id);

    $resp = patchAsset($controller, $asset->id, ['folder_id' => null]);

    expect($resp->getStatusCode())->toBe(200);
    expect(MediaAsset::query()->findOrFail($asset->id)->folder_id)->toBeNull();
});

test('PATCH refuses a folder of another principal with 422', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $foreignPrincipalId = createFilingPrincipal(2);
    $asset = ingestFiledSample($service, $principalId);
    $foreign = filingFolderService()->create($foreignPrincipalId, 'Corporate');

    $resp = patchAsset($controller, $asset->id, ['folder_id' => (string) $foreign->id]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNKNOWN_FOLDER');
});

test('a refused folder leaves the asset where it was', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $foreignPrincipalId = createFilingPrincipal(2);
    $asset = ingestFiledSample($service, $principalId);
    $own = filingFolderService()->create($principalId, 'Corporate');
    $foreign = filingFolderService()->create($foreignPrincipalId, 'Corporate');
    filingFolderService()->fileAsset($principalId, $asset, (string) $own->id);

    $resp = patchAsset($controller, $asset->id, ['folder_id' => (string) $foreign->id]);

    expect($resp->getStatusCode())->toBe(422);
    expect(MediaAsset::query()->findOrFail($asset->id)->folder_id)->toBe((string) $own->id);
});

test('PATCH refuses a folder id that is not a string with 400', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);

    $resp = patchAsset($controller, $asset->id, ['folder_id' => 42]);

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['message'])->toBe('folder_id must be a string or null.');
});

test('PATCH refuses a folder that already holds an asset with that filename with 422', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $folder = filingFolderService()->create($principalId, 'Corporate');
    $filed = ingestFiledSample($service, $principalId, 'dup.txt');
    $other = ingestFiledSample($service, $principalId, 'dup.txt');
    filingFolderService()->fileAsset($principalId, $filed, (string) $folder->id);

    $resp = patchAsset($controller, $other->id, ['folder_id' => (string) $folder->id]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('FOLDER_FILENAME_TAKEN');
    expect(MediaAsset::query()->findOrFail($other->id)->folder_id)->toBeNull();
});

test('PATCH stores the kind of the vocabulary', function (string $kind): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);
    // Start from a mark outside the vocabulary — the column stores
    // whatever it is given — so every value of the vocabulary is a
    // change and a write that stores nothing cannot pass for one.
    $asset->kind = 'marked';
    $asset->save();

    $resp = patchAsset($controller, $asset->id, ['kind' => $kind]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['kind'])->toBe($kind);
    expect(MediaAsset::query()->findOrFail($asset->id)->kind)->toBe($kind);
})->with(['asset', 'template', 'example']);

test('PATCH refuses a kind outside the vocabulary with 422', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);

    $resp = patchAsset($controller, $asset->id, ['kind' => 'banner']);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_KIND');
    expect(MediaAsset::query()->findOrFail($asset->id)->kind)->toBe('asset');
});

test('PATCH stores the folder and the kind in one request', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);
    $folder = filingFolderService()->create($principalId, 'Corporate');

    $resp = patchAsset($controller, $asset->id, [
        'folder_id' => (string) $folder->id,
        'kind'      => 'template',
    ]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['folder_id'])->toBe((string) $folder->id);
    expect($body['data']['kind'])->toBe('template');
});

/**
 * The kind is a filter hint, never a gate: an asset marked as a template
 * is still editable and still fileable, and the mark itself is the only
 * thing a write refuses it for.
 */
test('the kind is advisory — a marked asset is still editable and fileable', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $asset = ingestFiledSample($service, $principalId);
    $folder = filingFolderService()->create($principalId, 'Corporate');
    patchAsset($controller, $asset->id, ['kind' => 'template']);

    $resp = patchAsset($controller, $asset->id, [
        'filename'  => 'marked.txt',
        'folder_id' => (string) $folder->id,
    ]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['kind'])->toBe('template');
    expect($body['data']['filename'])->toBe('marked.txt');
    expect($body['data']['folder_id'])->toBe((string) $folder->id);
});

/**
 * A rename inside a folder is bound by the same filename rule as a
 * filing, so it is a 422 with a message — not a driver-level error.
 */
test('PATCH refuses a rename that would duplicate a name in the folder with 422', function (): void {
    [$service, $controller] = buildFilingController();
    $principalId = createFilingPrincipal(1);
    $folder = filingFolderService()->create($principalId, 'Corporate');
    $filed = ingestFiledSample($service, $principalId, 'taken.txt');
    $other = ingestFiledSample($service, $principalId, 'other.txt');
    filingFolderService()->fileAsset($principalId, $filed, (string) $folder->id);
    filingFolderService()->fileAsset($principalId, $other, (string) $folder->id);

    $resp = patchAsset($controller, $other->id, ['filename' => 'taken.txt']);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('FOLDER_FILENAME_TAKEN');
    expect(MediaAsset::query()->findOrFail($other->id)->filename)->toBe('other.txt');
});

/**
 * @return array{0: MediaArchiveService, 1: MediaArchiveAdminController}
 */
function buildFilingController(): array
{
    $tmp = sys_get_temp_dir() . '/spora-filing-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths      = new Paths(BASE_PATH);
    $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database   = new DatabaseAssetStore(50 * 1024 * 1024);
    $local      = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service    = MediaArchiveTestSupport::buildService($assetStore);

    return [
        $service,
        new MediaArchiveAdminController($service, filingAuth(), new MediaFolderService()),
    ];
}

/**
 * A principal with its user row — the principal is the tenant boundary a
 * folder is scoped to.
 */
function createFilingPrincipal(int $userId): int
{
    Capsule::table('users')->insert([
        'id'         => $userId,
        'email'      => "filing-user-{$userId}@spora.test",
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) Capsule::table('principals')->insertGetId([
        'type'       => 'user',
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function ingestFiledSample(
    MediaArchiveService $service,
    int $principalId,
    string $filename = 'sample.txt',
): MediaAsset {
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello',
        mime: 'text/plain',
        filename: $filename,
        userId: 1,
        principalId: $principalId,
        uploadSource: 'upload',
    ));
}

function filingFolderService(): MediaFolderService
{
    return new MediaFolderService();
}

/**
 * @param array<string, mixed> $body
 */
function patchAsset(MediaArchiveAdminController $controller, string $id, array $body): JsonResponse
{
    $req = Request::create("/api/v1/media/{$id}", 'PATCH', content: json_encode($body));
    $req->headers->set('Content-Type', 'application/json');

    return $controller->update($id, $req);
}

function filingAuth(): AuthService
{
    $auth = M::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn(1);
    $auth->shouldReceive('isAdmin')->andReturn(true);

    return $auth;
}
