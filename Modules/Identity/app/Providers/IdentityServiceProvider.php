<?php

declare(strict_types=1);

namespace Modules\Identity\Providers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Auth\JwtGuard;
use Modules\Identity\Console\JwtKeys;
use Modules\Identity\Console\PruneTokens;
use Modules\Identity\Services\SecurityLimits;
use Modules\Identity\Contracts\CurrentUser;
use Modules\Identity\Models\User;
use Modules\Identity\Services\Tokens;
use Nwidart\Modules\Support\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Identity';

    protected string $nameLower = 'identity';

    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    protected array $commands = [JwtKeys::class, PruneTokens::class];

    public function register(): void
    {
        parent::register();
        $this->app->scoped(CurrentUser::class, static function (): CurrentUser {
            $user = request()->user('api');
            if (! $user instanceof User) {
                throw new AuthenticationException;
            }

            return new CurrentUser($user->id, $user->role->value, $user->email_verified_at !== null);
        });
    }

    public function boot(): void
    {
        parent::boot();
        Auth::extend('jwt', static function (Application $app, string $name, array $config): JwtGuard {
            $guard = new JwtGuard(
                static fn (Request $request): ?User => app(Tokens::class)->user($request),
                $app['request'], Auth::createUserProvider($config['provider']),
            );
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
        SecurityLimits::register();
    }
}
