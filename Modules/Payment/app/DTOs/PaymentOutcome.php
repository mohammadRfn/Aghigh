<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

use Modules\Payment\Contracts\PaymentStatus;

final readonly class PaymentOutcome
{
    public function __construct(
        public string $orderId,
        public ?PaymentStatus $status,
        public ?string $reference = null,
        public ?string $failureCode = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
