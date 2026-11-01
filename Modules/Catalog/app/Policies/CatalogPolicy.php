<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Identity\Contracts\CurrentUser;

final class CatalogPolicy
{
    public function authorize(CurrentUser $actor): void
    {
        if ($actor->role !== 'admin') {
            throw new CatalogProblem('Administrator access is required.', 403, 'forbidden');
        }
    }
}
