<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

/** Read surface of Payment (compensation decisions and orders:verify-invariants). */
interface PaymentLedger
{
    public function status(string $orderId): ?PaymentStatus;

    /**
     * @param  list<string>  $orderIds
     * @return array<string, PaymentStatus> orders without a payment are absent
     */
    public function statuses(array $orderIds): array;
}
