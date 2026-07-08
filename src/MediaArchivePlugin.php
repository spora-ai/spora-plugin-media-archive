<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive;

use Spora\Apps\AppInterface;
use Spora\Plugins\AbstractPlugin;

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
    public function getName(): string
    {
        return 'Media Archive';
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
}
