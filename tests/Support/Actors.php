<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Identity\Models\User;

/** Test-only adapter: a verified user and a real access token, for tests of modules that must not import Identity. */
final class Actors
{
    /** @return array{id: string, token: string} */
    public static function user(string $role = 'customer'): array
    {
        $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);

        return ['id' => (string) $user->id, 'token' => IdentityTesting::access($user)];
    }
}
