<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

/**
 * Builds the rows a chat reference needs.
 *
 * The foreign keys are why this is a helper and not a one-liner: a
 * `task_history` row points at a `tasks` row, which points at an
 * `agents` row, which points at a `principals` row, which points at a
 * `users` row — so a history row cannot be written without the whole
 * chain, and the plugin's suite runs with SQLite's foreign keys on.
 */
final class ChatFixture
{
    private static int $sequence = 0;

    /**
     * A Media Archive row, so the asset can be resolved by the endpoint.
     *
     * The row is written directly rather than ingested: the ingest
     * pipeline writes the bytes and the derivatives, and a count does not
     * care about either.
     */
    public static function asset(string $id): string
    {
        Capsule::connection()->table('media_assets')->insert([
            'id' => $id,
            'asset_url' => '/api/v1/assets/' . $id,
            'storage_mode' => 'data_url',
            'media_type' => 'image',
            'mime_type' => 'image/png',
        ]);

        return $id;
    }

    /**
     * A chat that references the given assets, one history row per asset.
     *
     * @param  list<string> $mediaIds
     * @return int The task id of the chat.
     */
    public static function chatWithAttachments(array $mediaIds): int
    {
        $db = Capsule::connection();
        $userId = self::user($db);
        $principalId = self::principal($db, $userId);
        $agentId = self::agent($db, $principalId);

        $taskId = (int) $db->table('tasks')->insertGetId([
            'agent_id' => $agentId,
            'principal_id' => $principalId,
            'trigger_user_id' => $userId,
            'status' => 'COMPLETED',
            'user_prompt' => 'a fixture chat',
        ]);

        foreach ($mediaIds as $index => $mediaId) {
            $db->table('task_history')->insert([
                'task_id' => $taskId,
                'sequence' => $index + 1,
                'role' => 'user',
                'attachments' => json_encode(
                    [['media_id' => $mediaId, 'kind' => 'image']],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
        }

        return $taskId;
    }

    private static function user(Connection $db): int
    {
        self::$sequence++;

        return (int) $db->table('users')->insertGetId([
            'email' => 'chat-fixture-' . self::$sequence . '@example.test',
            'password' => 'not-a-real-hash',
            'registered' => time(),
        ]);
    }

    private static function principal(Connection $db, int $userId): int
    {
        return (int) $db->table('principals')->insertGetId([
            'type' => 'user',
            'user_id' => $userId,
        ]);
    }

    private static function agent(Connection $db, int $principalId): int
    {
        return (int) $db->table('agents')->insertGetId([
            'principal_id' => $principalId,
            'name' => 'Chat fixture',
        ]);
    }
}
