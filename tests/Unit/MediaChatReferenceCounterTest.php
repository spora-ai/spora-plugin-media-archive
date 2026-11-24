<?php

declare(strict_types=1);

use Spora\Plugins\MediaArchive\MediaChatReferenceCounter;
use Spora\Plugins\MediaArchive\Tests\Support\ChatFixture;

const CHAT_REF_ASSET = '11111111-1111-4111-8111-111111111111';
const CHAT_REF_OTHER_ASSET = '22222222-2222-4222-8222-222222222222';

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
