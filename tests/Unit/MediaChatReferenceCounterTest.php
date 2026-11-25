<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\MediaArchive\MediaChatReferenceCounter;
use Spora\Plugins\MediaArchive\Tests\Support\ChatFixture;

const CHAT_REF_ASSET = '11111111-1111-4111-8111-111111111111';
const CHAT_REF_OTHER_ASSET = '22222222-2222-4222-8222-222222222222';
const CHAT_REF_LETTERED_ASSET = 'aabbccdd-1111-4222-8333-444455556666';

it('counts the chats that reference an asset', function (): void {
    $counter = new MediaChatReferenceCounter();

    ChatFixture::chatWithAttachments([CHAT_REF_ASSET]);
    ChatFixture::chatWithAttachments([CHAT_REF_ASSET]);
    ChatFixture::chatWithAttachments([CHAT_REF_OTHER_ASSET]);

    expect($counter->countFor([CHAT_REF_ASSET]))->toBe([CHAT_REF_ASSET => 2]);
});

it('counts zero for an asset no chat references', function (): void {
    $counter = new MediaChatReferenceCounter();

    ChatFixture::chatWithAttachments([CHAT_REF_ASSET]);

    expect($counter->countFor([CHAT_REF_OTHER_ASSET]))->toBe([CHAT_REF_OTHER_ASSET => 0]);
});

it('reports every requested id, with zero for the unreferenced one', function (): void {
    $counter = new MediaChatReferenceCounter();

    ChatFixture::chatWithAttachments([CHAT_REF_ASSET]);

    expect($counter->countFor([CHAT_REF_ASSET, CHAT_REF_OTHER_ASSET]))->toBe([
        CHAT_REF_ASSET => 1,
        CHAT_REF_OTHER_ASSET => 0,
    ]);
});

it('counts a chat once however many of its turns reference the asset', function (): void {
    $counter = new MediaChatReferenceCounter();

    // One chat, two turns, the same asset in both.
    ChatFixture::chatWithAttachments([CHAT_REF_ASSET, CHAT_REF_ASSET]);

    expect($counter->countFor([CHAT_REF_ASSET]))->toBe([CHAT_REF_ASSET => 1]);
});

it('returns an empty map for an empty id list', function (): void {
    $counter = new MediaChatReferenceCounter();

    expect($counter->countFor([]))->toBe([]);
});

it('ignores a row whose attachments name another asset', function (): void {
    $counter = new MediaChatReferenceCounter();

    ChatFixture::chatWithAttachments([CHAT_REF_OTHER_ASSET]);

    expect($counter->countFor([CHAT_REF_ASSET]))->toBe([CHAT_REF_ASSET => 0]);
});

it('counts a reference whose stored id is spelled in upper case', function (): void {
    // MySQL keeps `attachments` in a native JSON column and compares it
    // byte by byte, so its LIKE folds no case at all. SQLite's LIKE
    // does fold ASCII case, and `case_sensitive_like` turns that off —
    // which makes the two engines behave alike, and the count must not
    // depend on which one the install runs.
    Capsule::connection()->statement('PRAGMA case_sensitive_like = ON');

    try {
        ChatFixture::chatWithAttachments([strtoupper(CHAT_REF_LETTERED_ASSET)]);

        $counter = new MediaChatReferenceCounter();

        expect($counter->countFor([CHAT_REF_LETTERED_ASSET]))->toBe([CHAT_REF_LETTERED_ASSET => 1]);
    } finally {
        Capsule::connection()->statement('PRAGMA case_sensitive_like = OFF');
    }
});

it('gives two spellings of one id the same count', function (): void {
    $counter = new MediaChatReferenceCounter();

    ChatFixture::chatWithAttachments([CHAT_REF_LETTERED_ASSET]);

    expect($counter->countFor([strtoupper(CHAT_REF_LETTERED_ASSET), CHAT_REF_LETTERED_ASSET]))->toBe([
        strtoupper(CHAT_REF_LETTERED_ASSET) => 1,
        CHAT_REF_LETTERED_ASSET => 1,
    ]);
});

it('ignores a row whose attachments are not JSON', function (): void {
    // The column is unvalidated TEXT on SQLite, so a row can hold
    // something that is not JSON at all. It carries no references, and
    // it must not stop the rows around it from being counted.
    ChatFixture::chatWithAttachments([CHAT_REF_ASSET]);
    ChatFixture::chatWithRawAttachments('not json at all');

    $counter = new MediaChatReferenceCounter();

    expect($counter->countFor([CHAT_REF_ASSET]))->toBe([CHAT_REF_ASSET => 1]);
});

it('ignores a row whose attachments are a JSON object', function (): void {
    // Core writes a list of attachment objects. A row whose root is an
    // object holds named fields, not attachments — its members are not
    // references, and the uuid in one of them names nothing.
    ChatFixture::chatWithRawAttachments(json_encode([
        'item' => ['media_id' => CHAT_REF_ASSET, 'kind' => 'image'],
    ], JSON_THROW_ON_ERROR));

    $counter = new MediaChatReferenceCounter();

    expect($counter->countFor([CHAT_REF_ASSET]))->toBe([CHAT_REF_ASSET => 0]);
});
