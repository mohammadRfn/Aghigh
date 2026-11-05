<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\PaymentOutcome;

/**
 * The write surface of Payment. Orders are referenced by id only (ADR 0001).
 * Call these OUTSIDE a database transaction: the gateway is slow and must not hold a PgBouncer slot.
 */
interface PaymentCharger
{
    /**
     * Charge an order at most once: the payments row is unique per order and the gateway call uses
     * the order id as its idempotency key, so retries and duplicate deliveries never double-charge.
     * A settled payment (succeeded, failed, refunded) is returned as is, without calling the gateway.
     *
     * @throws GatewayUnavailable transient, retry with backoff
     */
    public function charge(string $orderId, int $amountMinor, string $currency): PaymentOutcome;

    /**
     * Give the money back. Idempotent: refunded stays refunded; a pending (ambiguous) payment is first
     * reconciled with what the gateway recorded for the key (captured: refund it; never captured: void it).
     *
     * @throws GatewayUnavailable
     */
    public function refund(string $orderId): PaymentOutcome;
}
