<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 | Task 10: /api/v1/_debug/* exists for the load tests (APP_ENV=loadtest) and must not exist in production.
 | Same technique as Modules/Catalog/tests/Feature/BenchRoutesTest.php: load the route file under another environment.
 */

/** @return list<string> */
function sharedRouteUrisUnder(string $environment): array
{
    $old = app()->environment();
    app()->instance('env', $environment);
    $router = new Router(app('events'), app());
    $original = Route::getFacadeRoot();
    Route::swap($router);
    try {
        require module_path('Shared', 'routes/api.php');

        return array_map(static fn ($route): string => $route->uri(), $router->getRoutes()->getRoutes());
    } finally {
        Route::swap($original);
        app()->instance('env', $old);
    }
}

it('does not register debug routes in production or staging', function (string $environment): void {
    $uris = sharedRouteUrisUnder($environment);

    expect($uris)->not->toContain('_debug/client-ip')
        ->and($uris)->toContain('shared/ping');
})->with(['production', 'staging']);

it('registers the debug route for load tests', function (): void {
    expect(sharedRouteUrisUnder('loadtest'))->toContain('_debug/client-ip');
});
