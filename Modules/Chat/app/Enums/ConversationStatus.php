<?php

declare(strict_types=1);

namespace Modules\Chat\Enums;

enum ConversationStatus: string
{
    case Waiting = 'waiting';
    case Claimed = 'claimed';
    case Closed = 'closed';
}
