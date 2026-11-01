<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

it('does not register benchmark routes in production or staging', function (string $environment): void {
    $old = app()->environment();
    app()->instance('env', $environment);
    $router = new Router(app('events'), app());
    $original = Route::getFacadeRoot();
    Route::swap($router);
    try {
        require module_path('Catalog', 'routes/api.php');
        $uris = array_map(static fn ($route): string => $route->uri(), $router->getRoutes()->getRoutes());
        expect($uris)->not->toContain('_bench/naive/products');
        expect($uris)->toContain('products', 'admin/products');
    } finally {
        Route::swap($original);
        app()->instance('env', $old);
    }
})->with(['production', 'staging']);
