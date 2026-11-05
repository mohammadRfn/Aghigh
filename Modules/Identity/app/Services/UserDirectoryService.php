<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Models\User;

final class UserDirectoryService implements UserDirectory
{
    public function emailOf(string $userId): ?string
    {
        $email = User::query()->whereKey($userId)->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
