<?php

declare(strict_types=1);

namespace Modules\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Models\User;

final class IdentityDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Never provision a production account with a publicly documented password.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        User::factory()->create(['name' => 'Development User', 'email' => 'test@example.com']);
    }
}
