<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use Spora\Apps\AppInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point. Extending {@see AbstractPlugin} rather than
 * implementing {@see \Spora\Plugins\PluginInterface} directly means only the
 * hooks this plugin uses have to be overridden.
 *
 * The plugin surfaces the rows core's
 * {@see \Spora\Services\MediaArchive\MediaArchiveService} already indexes; it
 * contributes no ingestion of its own. The panel's Vue SPA ships as a
 * separate Composer package (`spora-ai/spora-plugin-media-archive-frontend`,
 * type `spora-plugin-frontend`) and the host SPA lazy-loads it from
 * `/plugins/<slug>/main.js`.
 *
 * The entry-point filename MUST match the FQCN (`MediaArchivePlugin.php` →
 * `Spora\Plugins\MediaArchive\MediaArchivePlugin`): the manifest's `class`
 * is resolved through the package's PSR-4 mapping, and a class that is not
 * autoloadable fails the boot with
 * {@see \Spora\Plugins\Exceptions\PluginLoadFailedException}.
 *
 * DI bindings and routes are registered through
 * {@see EventSubscriberInterface} — core has no per-plugin fallback hook —
 * and the loader wires subscribers outside its warm-cache branch, so they
 * survive a warm boot.
 */
final class MediaArchivePlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const ROUTE_MEDIA_ITEM = '/api/v1/media/{id}';

    private const AUTH = [AuthMiddleware::class, CsrfMiddleware::class];

    public function getName(): string
    {
        return 'Media Archive';
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Force-inject {@see \Spora\Services\MediaArchive\MediaDerivativeService}
     * into {@see MediaArchiveAdminController}'s nullable `?MediaDerivativeService`
     * ctor arg.
     *
     * `\DI\autowire()` honours the ctor's `= null` default for a nullable
     * param, which silently skips the injection; the detail page then never
     * sees the persisted derivatives on reload.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            MediaArchiveAdminController::class => \DI\autowire()
                ->constructorParameter('derivatives', \DI\get(\Spora\Services\MediaArchive\MediaDerivativeService::class)),
        ]);
    }

    /**
     * Register the plugin-owned admin routes behind Auth + CSRF.
     *
     * Fires per request after the project's App routes are registered
     * ({@see \Spora\Plugins\PluginLoader::registerRoutes()}).
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $event->routes()->addRoute('GET', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'show'], self::AUTH);
        $event->routes()->addRoute('PATCH', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'update'], self::AUTH);
        $event->routes()->addRoute('DELETE', self::ROUTE_MEDIA_ITEM, [MediaArchiveAdminController::class, 'destroy'], self::AUTH);
        $event->routes()->addRoute('POST', self::ROUTE_MEDIA_ITEM . '/public-token/refresh', [MediaArchiveAdminController::class, 'refreshPublicToken'], self::AUTH);
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
     * Contribute the archive's rows to the host ⌘K palette, so an asset is
     * findable by what it contains rather than only by browsing the grid.
     *
     * No DI definition is needed: the registry resolves provider classes
     * straight from the container, and this one has no constructor
     * arguments.
     *
     * @return array<int, class-string<\Spora\Search\SearchProviderInterface>>
     */
    public function searchProviders(): array
    {
        return [
            MediaAssetSearchProvider::class,
        ];
    }

    /**
     * The plugin's `locales/` directory — one `<lang>.json` per language.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
