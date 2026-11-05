<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

enum PaymentStatus: string
{
    case Pending = 'pending';     // row exists, outcome unknown (in flight, or an ambiguous gateway timeout)
    case Succeeded = 'succeeded';
    case Failed = 'failed';       // declined, or voided after a timeout the gateway never captured
    case Refunded = 'refunded';
}
