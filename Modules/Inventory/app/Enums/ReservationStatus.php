<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Committed = 'committed';
    case Expired = 'expired';
}
