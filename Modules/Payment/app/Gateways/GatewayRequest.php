<?php

declare(strict_types=1);

namespace Modules\Payment\Gateways;

final readonly class GatewayRequest
{
    /** @param int $attempt 1-based call number for this key; the fake gateway derives its randomness from it */
    public function __construct(
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public int $attempt = 1,
        public ?string $reference = null,
    ) {}
}
