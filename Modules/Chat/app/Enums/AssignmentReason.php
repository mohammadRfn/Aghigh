<?php

declare(strict_types=1);

namespace Modules\Chat\Enums;

/** claimed = still open (released_at null); released / closed = how the assignment ended. */
enum AssignmentReason: string
{
    case Claimed = 'claimed';
    case Released = 'released';
    case Closed = 'closed';
}
