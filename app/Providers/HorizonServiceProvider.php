<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Task 11: outside `local` the dashboard is reachable only through Traefik's `admin` entrypoint, which is
        // published on 127.0.0.1 (SSH tunnel). That router adds X-Admin-Edge-Token = HORIZON_ADMIN_TOKEN; the public
        // routers remove the header and deny /horizon (docker/traefik/prod/dynamic/routers.yml). There is no
        // session user (JWT is stateless), so the token is the whole check. No token configured = closed.
        Gate::define('viewHorizon', function ($user = null): bool {
            $expected = config('horizon.admin_token');
            $given = request()->headers->get('X-Admin-Edge-Token');

            return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
        });
    }
}
