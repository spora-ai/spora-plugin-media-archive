<?php

declare(strict_types=1);

use Spora\Plugins\MediaArchive\MediaArchiveApp;
use Spora\Plugins\MediaArchive\MediaArchivePlugin;

/**
 * The plugin contributes exactly one admin panel — the Media Archive UI.
 * Asserting the count catches accidental additions: if a future change adds
 * a second `apps()` entry, the operator's navbar will silently grow.
 */
it('contributes exactly one admin app', function (): void {
    $plugin = new MediaArchivePlugin();

    expect($plugin->apps())->toHaveCount(1);
});

it('registers MediaArchiveApp in apps()', function (): void {
    $plugin = new MediaArchivePlugin();

    expect($plugin->apps())->toContain(MediaArchiveApp::class);
});

it('advertises Media Archive as its name', function (): void {
    // The default {@see \Spora\Plugins\AbstractPlugin::getName()} would
    // return "Media Archive" via reflection on the class short name, but
    // we override it explicitly so the value is greppable and not coupled
    // to the FQCN.
    $plugin = new MediaArchivePlugin();

    expect($plugin->getName())->toBe('Media Archive');
});

it('contributes no tools, drivers, recipes, or migrations', function (): void {
    // The Media Archive is a presentational plugin — it surfaces rows
    // already indexed by spora-core's MediaArchiveService. Locking the
    // empty defaults documents that this plugin is intentionally inert on
    // those surfaces and guards against accidental drift.
    $plugin = new MediaArchivePlugin();

    expect($plugin->tools())->toBe([]);
    expect($plugin->drivers())->toBe([]);
    expect($plugin->recipePaths())->toBe([]);
    expect($plugin->migrationsPath())->toBeNull();
    expect($plugin->schemaVersion())->toBe(0);
});

it('declares no extra PSR-4 mappings', function (): void {
    // The plugin's own composer.json handles autoloading. The runtime hook
    // is only for plugins that ship PSR-4 prefixes *not* declared in
    // composer.json — typically legacy bridges or generated code.
    $plugin = new MediaArchivePlugin();

    expect($plugin->autoload())->toBe([]);
});
