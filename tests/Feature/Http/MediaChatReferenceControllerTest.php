<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Feature\Http;

use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Plugins\MediaArchive\Http\MediaChatReferenceController;
use Spora\Plugins\MediaArchive\MediaChatReferenceCounter;
use Spora\Plugins\MediaArchive\Tests\Support\ChatFixture;
use Spora\Plugins\MediaArchive\Tests\Support\MediaArchiveTestSupport;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /api/v1/media/chat-references` — how many chats reference each
 * of the assets the panel is about to delete.
 */

const ENDPOINT_ASSET = '33333333-3333-4333-8333-333333333333';
const ENDPOINT_OTHER_ASSET = '44444444-4444-4444-8444-444444444444';
const ENDPOINT_LETTERED_ASSET = 'aabbccdd-1111-4222-8333-444455556666';

it('counts the chats that reference an asset', function (): void {
    $controller = buildController();
    ChatFixture::asset(ENDPOINT_ASSET);
    ChatFixture::chatWithAttachments([ENDPOINT_ASSET]);
    ChatFixture::chatWithAttachments([ENDPOINT_ASSET]);

    $response = $controller->chatReferences(jsonRequest([ENDPOINT_ASSET]));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([ENDPOINT_ASSET => 2]);
});

it('reports zero for an asset no chat references', function (): void {
    $controller = buildController();
    ChatFixture::asset(ENDPOINT_OTHER_ASSET);

    $response = $controller->chatReferences(jsonRequest([ENDPOINT_OTHER_ASSET]));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([ENDPOINT_OTHER_ASSET => 0]);
});

it('reports every requested id, with zero for the unreferenced one', function (): void {
    $controller = buildController();
    ChatFixture::asset(ENDPOINT_ASSET);
    ChatFixture::asset(ENDPOINT_OTHER_ASSET);
    ChatFixture::chatWithAttachments([ENDPOINT_ASSET]);

    $response = $controller->chatReferences(jsonRequest([ENDPOINT_ASSET, ENDPOINT_OTHER_ASSET]));

    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([
        ENDPOINT_ASSET => 1,
        ENDPOINT_OTHER_ASSET => 0,
    ]);
});

it('reports zero for an id that names no asset', function (): void {
    // Existence-hiding: an id the caller cannot see is a count of zero,
    // not a refusal and not a 404.
    $controller = buildController();

    $response = $controller->chatReferences(jsonRequest(['55555555-5555-4555-8555-555555555555']));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe(['55555555-5555-4555-8555-555555555555' => 0]);
});

it('reports zero for an asset the caller cannot see', function (): void {
    // Existence-hiding reaches further than a missing row: the asset
    // exists and belongs to somebody else.
    $controller = buildController(isAdmin: false);
    ChatFixture::asset(ENDPOINT_OTHER_ASSET, ownerId: 999);
    ChatFixture::chatWithAttachments([ENDPOINT_OTHER_ASSET]);

    $response = $controller->chatReferences(jsonRequest([ENDPOINT_OTHER_ASSET]));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([ENDPOINT_OTHER_ASSET => 0]);
});

it('answers under the id the caller asked for', function (): void {
    $controller = buildController();
    ChatFixture::asset(ENDPOINT_LETTERED_ASSET);
    ChatFixture::chatWithAttachments([ENDPOINT_LETTERED_ASSET]);

    $response = $controller->chatReferences(jsonRequest([strtoupper(ENDPOINT_LETTERED_ASSET)]));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([strtoupper(ENDPOINT_LETTERED_ASSET) => 1]);
});

it('answers both spellings of one id with the same count', function (): void {
    $controller = buildController();
    ChatFixture::asset(ENDPOINT_LETTERED_ASSET);
    ChatFixture::chatWithAttachments([ENDPOINT_LETTERED_ASSET]);

    $response = $controller->chatReferences(jsonRequest([
        strtoupper(ENDPOINT_LETTERED_ASSET),
        ENDPOINT_LETTERED_ASSET,
    ]));

    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toBe([
        strtoupper(ENDPOINT_LETTERED_ASSET) => 1,
        ENDPOINT_LETTERED_ASSET => 1,
    ]);
});

it('rejects a malformed id with 422', function (): void {
    $controller = buildController();

    $response = $controller->chatReferences(jsonRequest(['not-a-uuid']));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('rejects a uuid with a trailing newline with 422', function (): void {
    // PCRE's `$` also matches before a trailing newline, so the id would
    // pass the shape check and reach the counter as an id that names
    // nothing.
    $controller = buildController();

    $response = $controller->chatReferences(jsonRequest([ENDPOINT_ASSET . "\n"]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('rejects an empty id list with 422', function (): void {
    $controller = buildController();

    $response = $controller->chatReferences(jsonRequest([]));

    expect($response->getStatusCode())->toBe(422);
});

it('rejects a body without an ids array with 422', function (): void {
    $controller = buildController();

    $request = Request::create('/api/v1/media/chat-references', 'POST', content: json_encode(['nope' => []]));
    $request->headers->set('Content-Type', 'application/json');

    $response = $controller->chatReferences($request);

    expect($response->getStatusCode())->toBe(422);
});

it('rejects a body that is not JSON with 422', function (): void {
    $controller = buildController();

    $request = Request::create('/api/v1/media/chat-references', 'POST', content: 'not json');
    $request->headers->set('Content-Type', 'application/json');

    $response = $controller->chatReferences($request);

    expect($response->getStatusCode())->toBe(422);
});

it('rejects more than 64 ids with 422', function (): void {
    $controller = buildController();

    $ids = [];
    for ($i = 0; $i < 65; $i++) {
        $ids[] = sprintf('00000000-0000-4000-8000-%012d', $i);
    }

    $response = $controller->chatReferences(jsonRequest($ids));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['message'])->toContain('64');
});

it('accepts exactly 64 ids', function (): void {
    $controller = buildController();

    $ids = [];
    for ($i = 0; $i < 64; $i++) {
        $ids[] = sprintf('00000000-0000-4000-8000-%012d', $i);
    }

    $response = $controller->chatReferences(jsonRequest($ids));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['counts'])->toHaveCount(64);
});

/**
 * @param  list<string> $ids
 */
function jsonRequest(array $ids): Request
{
    $request = Request::create(
        '/api/v1/media/chat-references',
        'POST',
        content: json_encode(['ids' => $ids], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

function buildController(bool $isAdmin = true, int $userId = 1): MediaChatReferenceController
{
    $tmp = sys_get_temp_dir() . '/spora-chat-refs-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $assetStore = new AutoAssetStore(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore($paths, $security, 50 * 1024 * 1024),
        1_048_576,
    );

    $auth = M::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);
    $auth->shouldReceive('isAdmin')->andReturn($isAdmin);

    return new MediaChatReferenceController(
        new MediaChatReferenceCounter(),
        MediaArchiveTestSupport::buildService($assetStore),
        $auth,
    );
}
