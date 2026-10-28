<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Identity\Database\Factories\UserFactory;
use Modules\Identity\Enums\Role;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property int $failed_login_count
 * @property ?Carbon $email_verified_at
 * @property ?Carbon $locked_until
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'failed_login_count', 'locked_until'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_count' => 'integer',
            'role' => Role::class,
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
