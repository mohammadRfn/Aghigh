<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

enum Role: string
{
    case Customer = 'customer';
    case SupportAgent = 'support_agent';
    case Admin = 'admin';
}
