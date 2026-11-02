<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Modules\Identity\Contracts\CurrentUser;
use Modules\Inventory\Exceptions\InventoryProblem;

final class InventoryPolicy
{
    public function authorize(CurrentUser $actor): void
    {
        if ($actor->role !== 'admin') {
            throw new InventoryProblem('Administrator access is required.', 403, 'forbidden');
        }
    }
}
