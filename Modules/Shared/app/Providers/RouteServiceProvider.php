<?php

declare(strict_types=1);

namespace Modules\Shared\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Shared';

    /**
     * Called before routes are registered.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the module's API routes (served under /api/v1).
     */
    public function map(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(module_path($this->name, '/routes/api.php'));
    }
}
