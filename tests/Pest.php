<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Plugin-local test helpers and global Pest hooks. The host spora-core
| test suite defines the same `bootAuthLayer` / `simulateLoggedInSession`
| / `clearSession` / `callController` helpers; we redefine them here so
| the plugin's pest suite is self-contained and runs against the
| symlinked spora-core (via the plugin's `composer.json` repositories
| block, or a `composer install` against a tagged release).
|
| Unlike the memories plugin, this suite exercises controllers that read
| and write to the `media_assets` / `users` / `media_derivatives` core
| tables, so the `beforeEach` hook installs the full core migration set
| via `DatabaseSchemaInstaller` against the in-memory SQLite.
|
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);

// Shared test helpers (available to all test files)

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): Spora\Auth\AuthService
{
    $pdo  = Capsule::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new Spora\Auth\AuthService($auth);
}

/**
 * Simulate a logged-in session by populating the PHP session superglobal
 * the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME]  = null;
}

/**
 * Clear the session, simulating a not-logged-in state.
 */
function clearSession(): void
{
    $_SESSION = [];
}

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        // Vendor's Database::bootDatabaseConnectionOnly() treats
        // `db_path` verbatim as a file path (it `touch`es the parent
        // dir and the file itself before the connection opens), so a
        // literal ":memory:" would create a stray ":memory:" file in
        // the cwd. Use a per-process tmp file instead — the
        // transaction rollback in afterEach discards everything anyway.
        $tmpDb = sys_get_temp_dir() . '/spora-plugin-media-archive-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => $tmpDb]);
        $db->bootDatabaseConnectionOnly();

        // Install the full core migration set so the controller under
        // test can SELECT / INSERT against `users`, `media_assets`,
        // `principals`, etc. — the plugin does not own any of these
        // tables. DatabaseSchemaInstaller is idempotent across the
        // schema_versions table, so successive test runs against the
        // same connection short-circuit on the second beforeEach.
        $installer = new DatabaseSchemaInstaller(null, null, null);
        $installer->install();

        Capsule::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Database::resetBootState();
        M::close();
    })
    ->in(__DIR__);
