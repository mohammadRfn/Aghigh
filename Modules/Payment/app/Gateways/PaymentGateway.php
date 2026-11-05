<?php

declare(strict_types=1);

namespace Modules\Payment\Gateways;

use Modules\Payment\Contracts\GatewayUnavailable;

/** Internal port to the payment provider. Only FakeGateway exists (showcase project). */
interface PaymentGateway
{
    /** @throws GatewayUnavailable */
    public function charge(GatewayRequest $request): GatewayResult;

    /** @throws GatewayUnavailable */
    public function refund(GatewayRequest $request): GatewayResult;

    /** What the gateway recorded for a charge idempotency key; null = never captured. */
    public function find(string $idempotencyKey): ?GatewayResult;
}
