<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController;
use Spora\Plugins\MediaArchive\MediaArchiveApp;
use Spora\Plugins\MediaArchive\MediaArchivePlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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

it('contributes no tools or migrations', function (): void {
    // The Media Archive is a presentational plugin — it surfaces rows
    // already indexed by spora-core's MediaArchiveService. Locking the
    // empty defaults documents that this plugin is intentionally inert on
    // those surfaces and guards against accidental drift.
    $plugin = new MediaArchivePlugin();

    expect($plugin->tools())->toBe([]);
    expect($plugin->migrationsPath())->toBeNull();
    expect($plugin->schemaVersion())->toBe(0);
});

it('subscribes to the two lifecycle events it needs', function (): void {
    // PSR-14 opt-in: the plugin class itself declares which dispatchers
    // it listens to. Catching an accidental drop (someone removes the
    // `implements EventSubscriberInterface` line) preserves the runtime
    // wiring seen in production — see
    // spora-workspace/plans/extension-interface-events.md.
    $plugin = new MediaArchivePlugin();

    expect($plugin)->toBeInstanceOf(EventSubscriberInterface::class);

    $events = MediaArchivePlugin::getSubscribedEvents();
    expect($events)->toHaveKeys([
        ContainerBuildingEvent::class,
        RoutesRegisteringEvent::class,
    ]);
    expect($events[ContainerBuildingEvent::class])->toBe('onContainerBuilding');
    expect($events[RoutesRegisteringEvent::class])->toBe('onRoutesRegistering');
});

it('wires MediaArchiveAdminController into the PHP-DI container on ContainerBuildingEvent', function (): void {
    // The controller must be explicitly registered: without
    // \DI\autowire() + constructorParameter(), PHP-DI resolves
    // `$container->get($controllerClass)` via the no-definition path
    // and falls back to `new $controllerClass()` with no arguments,
    // so the nullable `?MediaDerivativeService` ctor arg stays null
    // and `GET /api/v1/media/{id}` returns `derivatives: []` on reload.
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new MediaArchivePlugin());
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $container = $builder->build();
    expect($container->has(MediaArchiveAdminController::class))->toBeTrue();
});

it('registers the four admin routes on RoutesRegisteringEvent', function (): void {
    // Belt-and-braces against a route accidentally regressing to its old
    // spora-core home (the list endpoint stays in core; these four move
    // here so the plugin owns its CRUD end-to-end).
    $routes = new MiddlewareRouteCollector(new Std(), new GroupCountBased());

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new MediaArchivePlugin());
    $dispatcher->dispatch(new RoutesRegisteringEvent($routes));

    $records = collectRegisteredRoutes($routes);

    $actions = array_map(
        static fn(array $record): string => $record['action'],
        $records,
    );

    expect($actions)->toEqualCanonicalizing([
        'show',
        'update',
        'destroy',
        'refreshPublicToken',
    ]);

    // Every plugin-owned route is gated behind Auth + CSRF — a future
    // contributor dropping the middleware would be a security regression,
    // not a feature.
    foreach ($records as $record) {
        $expected  = "{$record['method']} {$record['regex']}";
        expect($record['middleware'])->toContain(AuthMiddleware::class);
        expect($record['middleware'])->toContain(CsrfMiddleware::class);
    }

    // The handler dispatched by every record should be a method on
    // MediaArchiveAdminController — otherwise the route would dispatch
    // into the wrong class and 404 at runtime.
    foreach ($records as $record) {
        expect($record['controller'])->toBe(MediaArchiveAdminController::class);
    }
});

/**
 * Walk FastRoute's compiled regex/routeMap structure for a
 * {@see MiddlewareRouteCollector} and yield each registered route's
 * method, regex, handler, and middleware list. Used to introspect what
 * the listener under test added without booting a Router.
 *
 * @return list<array{method: string, regex: string, controller: class-string, action: string, middleware: list<string>}>
 */
function collectRegisteredRoutes(MiddlewareRouteCollector $routes): array
{
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>} $data */
    $data = $routes->getData();
    [, $variableRoutes] = $data;

    $records = [];
    foreach ($variableRoutes as $method => $chunks) {
        foreach ($chunks as $chunk) {
            $regex = $chunk['regex'];
            foreach ($chunk['routeMap'] as [$handlerEntry]) {
                /** @var array{handler: array{0: class-string, 1: string}, middleware: list<class-string>} $handlerEntry */
                $handler = $handlerEntry['handler'];
                [$controller, $action] = $handler;
                /** @var list<class-string> $middleware */
                $middleware = $handlerEntry['middleware'];
                $records[] = [
                    'method'     => (string) $method,
                    'regex'      => (string) $regex,
                    'controller' => $controller,
                    'action'     => $action,
                    'middleware' => $middleware,
                ];
            }
        }
    }

    return $records;
}
