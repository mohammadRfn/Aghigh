<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Identity\Models\User;

/** Test-only integration adapter; Catalog itself imports only CurrentUser. */
final class CatalogTesting
{
    public static function configure(): void
    {
        IdentityTesting::configure();
        config(['cache.stores.cache' => ['driver' => 'array', 'serialize' => false]]);
        Cache::forgetDriver('cache');
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    public static function token(string $role = 'admin'): string
    {
        $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);

        return IdentityTesting::access($user);
    }
}
