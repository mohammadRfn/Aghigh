<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Payment\Contracts\GatewayUnavailable;
use Modules\Payment\Contracts\PaymentCharger;
use Modules\Payment\Contracts\PaymentLedger;
use Modules\Payment\Contracts\PaymentStatus;
use Modules\Payment\DTOs\PaymentOutcome;
use Modules\Payment\Exceptions\RefundNotApproved;
use Modules\Payment\Gateways\GatewayRequest;
use Modules\Payment\Gateways\GatewayResult;
use Modules\Payment\Gateways\PaymentGateway;
use stdClass;

/**
 * payments.order_id is UNIQUE and every status change is a conditional UPDATE ... WHERE status = ?,
 * so any number of duplicate deliveries settle a payment exactly once. Gateway calls run outside
 * transactions; the order id (refund: "refund:<order id>") is the gateway idempotency key.
 */
final class PaymentService implements PaymentCharger, PaymentLedger
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function charge(string $orderId, int $amountMinor, string $currency): PaymentOutcome
    {
        if ($amountMinor < 0 || strlen($currency) !== 3) {
            throw new InvalidArgumentException('A charge needs a non-negative amount and an ISO currency code.');
        }
        $now = now();
        DB::table('payments')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'amount_minor' => $amountMinor, 'currency' => $currency,
            'status' => PaymentStatus::Pending->value, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $payment = $this->row($orderId) ?? throw new LogicException('Payment row vanished for order '.$orderId);
        if ((int) $payment->amount_minor !== $amountMinor || (string) $payment->currency !== $currency) {
            throw new LogicException('A payment for this order already exists with a different amount.');
        }
        if ((string) $payment->status !== PaymentStatus::Pending->value) {
            return self::outcome($payment); // settled earlier: never call the gateway again
        }

        $attempt = $this->countAttempt((string) $payment->id);
        try {
            $result = $this->gateway->charge(new GatewayRequest($orderId, $amountMinor, $currency, $attempt));
        } catch (GatewayUnavailable $error) {
            DB::table('payments')->where('id', $payment->id)->where('status', PaymentStatus::Pending->value)
                ->update(['last_error' => 'gateway_unavailable', 'updated_at' => now()]);
            throw $error;
        }
        $this->settle((string) $payment->id, $result);

        return self::outcome($this->row($orderId) ?? $payment);
    }

    public function refund(string $orderId): PaymentOutcome
    {
        $payment = $this->row($orderId);
        if ($payment === null) {
            return new PaymentOutcome($orderId, null);
        }
        if ((string) $payment->status === PaymentStatus::Pending->value) {
            // Ambiguous timeout: ask what the gateway recorded for the key before deciding.
            $known = $this->gateway->find($orderId);
            if ($known === null) {
                DB::table('payments')->where('id', $payment->id)->where('status', PaymentStatus::Pending->value)->update([
                    'status' => PaymentStatus::Failed->value, 'failure_code' => 'voided', 'settled_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $this->settle((string) $payment->id, $known);
            }
            $payment = $this->row($orderId) ?? $payment;
        }
        if ((string) $payment->status !== PaymentStatus::Succeeded->value) {
            return self::outcome($payment); // refunded already, failed or voided: nothing to give back
        }

        $attempt = $this->countAttempt((string) $payment->id);
        $result = $this->gateway->refund(new GatewayRequest(
            'refund:'.$orderId, (int) $payment->amount_minor, (string) $payment->currency, $attempt, $payment->gateway_reference === null ? null : (string) $payment->gateway_reference,
        ));
        if (! $result->approved) {
            throw new RefundNotApproved('The gateway did not approve the refund.');
        }
        DB::table('payments')->where('id', $payment->id)->where('status', PaymentStatus::Succeeded->value)->update([
            'status' => PaymentStatus::Refunded->value, 'refund_reference' => $result->reference, 'refunded_at' => now(), 'updated_at' => now(),
        ]);

        return self::outcome($this->row($orderId) ?? $payment);
    }

    public function status(string $orderId): ?PaymentStatus
    {
        $status = DB::table('payments')->where('order_id', $orderId)->value('status');

        return is_string($status) ? PaymentStatus::from($status) : null;
    }

    public function statuses(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $statuses = [];
        foreach (DB::table('payments')->whereIn('order_id', $orderIds)->get(['order_id', 'status']) as $row) {
            $statuses[(string) $row->order_id] = PaymentStatus::from((string) $row->status);
        }

        return $statuses;
    }

    /** pending -> succeeded | failed, exactly once (a webhook may have settled it first). */
    public function settle(string $paymentId, GatewayResult $result): bool
    {
        return DB::table('payments')->where('id', $paymentId)->where('status', PaymentStatus::Pending->value)->update([
            'status' => $result->approved ? PaymentStatus::Succeeded->value : PaymentStatus::Failed->value,
            'gateway_reference' => $result->reference,
            'failure_code' => $result->approved ? null : $result->code,
            'last_error' => null, 'settled_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    private function countAttempt(string $paymentId): int
    {
        DB::table('payments')->where('id', $paymentId)->increment('attempts', 1, ['updated_at' => now()]);

        return (int) DB::table('payments')->where('id', $paymentId)->value('attempts');
    }

    /** @phpstan-impure reads the current row: never reuse an earlier result */
    private function row(string $orderId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = DB::table('payments')->where('order_id', $orderId)->first();

        return $row;
    }

    private static function outcome(stdClass $payment): PaymentOutcome
    {
        return new PaymentOutcome(
            (string) $payment->order_id,
            PaymentStatus::from((string) $payment->status),
            $payment->gateway_reference === null ? null : (string) $payment->gateway_reference,
            $payment->failure_code === null ? null : (string) $payment->failure_code,
        );
    }
}
