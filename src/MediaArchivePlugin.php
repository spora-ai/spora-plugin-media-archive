<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use DI\ContainerBuilder;
use Spora\Apps\AppInterface;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController;

/**
 * Plugin entry point — extending {@see AbstractPlugin} (rather than directly
 * implementing {@see \Spora\Plugins\PluginInterface}) means we only have to
 * override the hooks we actually use.
 *
 * The Media Archive is a presentational plugin: it surfaces the rows already
 * indexed by spora-core's {@see \Spora\Services\MediaArchive\MediaArchiveService}.
 * No tools, drivers, recipes, or migrations are contributed — the plugin's
 * job is to ship the admin panel that consumes the REST API.
 *
 * The Vue SPA that powers the panel is delivered as a separate Composer
 * package (`spora-ai/spora-plugin-media-archive-frontend`, type
 * `spora-plugin-frontend`). The `SporaPluginFrontendInstaller` in
 * `spora-installer` copies that package's `frontend/` directory into
 * `public/plugins/spora-plugin-media-archive-frontend/` so the SPA can
 * lazy-load it via `/plugins/<slug>/main.js`.
 *
 * PSR-4 note: the entry-point filename MUST match the FQCN
 * (`MediaArchivePlugin.php` → `Spora\Plugins\MediaArchive\MediaArchivePlugin`).
 * {@see \Spora\Plugins\PluginLoader} resolves the class via PSR-4 autoloading
 * and throws {@see \Spora\Plugins\Exceptions\PluginLoadFailedException} on
 * miss — see CLAUDE.md § Plugin authoring.
 */
final class MediaArchivePlugin extends AbstractPlugin
{
    private const ROUTE_MEDIA_ITEM = '/api/v1/media/{id}';

    public function getName(): string
    {
        return 'Media Archive';
    }

    /**
     * PHP-DI needs explicit `\DI\autowire()` registration for plugin
     * controllers — without it, `$container->get($controllerClass)`
     * resolves via the no-definition path and PHP-DI falls back to
     * `new $controllerClass()` with no arguments, so the optional
     * `?MediaDerivativeService` ctor arg stays null and the detail
     * page loses its derivatives on reload. `MemoriesPlugin::register()`
     * follows the same pattern.
     */
    public function register(ContainerBuilder $builder): void
    {
        // `\DI\autowire()` honours the ctor's `= null` defaults for
        // nullable params, which silently skips the
        // `MediaDerivativeService` injection. Force the param so the
        // detail page gets derivatives on reload.
        $builder->addDefinitions([
            MediaArchiveAdminController::class => \DI\autowire()
                ->constructorParameter('derivatives', \DI\get(\Spora\Services\MediaArchive\MediaDerivativeService::class)),
        ]);
    }

    /**
     * Single admin-panel entry. {@see MediaArchiveApp} implements
     * {@see \Spora\Apps\VueAppInterface} so the host SPA's generic
     * `/apps/:appName` loader picks it up via `GET /api/v1/apps`.
     *
     * @return array<int, class-string<AppInterface>>
     */
    public function apps(): array
    {
        return [
            MediaArchiveApp::class,
        ];
    }

    /**
     * Register the four plugin-owned admin routes
     * (`GET`/`PATCH`/`DELETE` `/api/v1/media/{id}` and
     * `POST /api/v1/media/{id}/public-token/refresh`) behind Auth + CSRF.
     *
     * These used to live in spora-core's `MediaArchiveController`; spora-core
     * PR #221 trimmed that controller to `index()` only and the four
     * mutating endpoints moved here so the plugin owns its CRUD end-to-end,
     * mirroring the `spora-plugin-memories` pattern. The list endpoint
     * stays in core because the composer and upload UI also call it.
     *
     * Invoked per request after the project's App routes are registered
     * (see {@see \Spora\Plugins\PluginLoader::registerRoutes()}).
     */
    public function routes(MiddlewareRouteCollector $r): void
    {
        $auth = [AuthMiddleware::class, CsrfMiddleware::class];

        $r->addRoute('GET', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'show'], $auth);
        $r->addRoute('PATCH', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'update'], $auth);
        $r->addRoute('DELETE', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'destroy'], $auth);
        $r->addRoute('POST', self::ROUTE_MEDIA_ITEM . '/public-token/refresh', [MediaArchiveAdminController::class, 'refreshPublicToken'], $auth);
    }
}
