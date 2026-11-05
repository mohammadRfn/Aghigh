<?php

declare(strict_types=1);

namespace Modules\Payment\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payment\Contracts\PaymentStatus;
use Modules\Payment\Gateways\GatewayResult;
use Modules\Payment\Services\PaymentService;

/**
 * Applies a verified gateway event exactly once: payment_webhook_events.event_id is UNIQUE and the
 * insert and its effect share one transaction (a failed effect rolls the event row back, so the
 * gateway's retry is processed). Effects are conditional updates on payments only.
 */
final class PaymentWebhooks
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @param  array{id: string, type: string, data: array<string, mixed>}  $event
     * @return bool false when the event id was already processed
     */
    public function handle(array $event, int $signedAt): bool
    {
        $orderId = $event['data']['order_id'] ?? null;
        $orderId = is_string($orderId) && Str::isUuid($orderId) ? strtolower($orderId) : null;

        return DB::transaction(function () use ($event, $signedAt, $orderId): bool {
            $inserted = DB::table('payment_webhook_events')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'event_id' => $event['id'], 'type' => $event['type'], 'order_id' => $orderId,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'signed_at' => CarbonImmutable::createFromTimestamp($signedAt), 'received_at' => CarbonImmutable::now(),
            ]);
            if ($inserted === 0) {
                return false;
            }
            if ($orderId !== null) {
                $this->apply($event['type'], $orderId, $event['data']);
            }

            return true;
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(string $type, string $orderId, array $data): void
    {
        $paymentId = DB::table('payments')->where('order_id', $orderId)->value('id');
        if (! is_string($paymentId)) {
            return; // unknown order: recorded for audit, nothing to change
        }
        $reference = is_string($data['reference'] ?? null) ? substr($data['reference'], 0, 64) : null;
        match ($type) {
            'charge.succeeded' => $this->payments->settle($paymentId, GatewayResult::approved($reference ?? 'webhook')),
            'charge.failed' => $this->payments->settle($paymentId, GatewayResult::declined(is_string($data['code'] ?? null) ? substr($data['code'], 0, 32) : 'declined')),
            'refund.succeeded' => DB::table('payments')->where('id', $paymentId)->where('status', PaymentStatus::Succeeded->value)->update([
                'status' => PaymentStatus::Refunded->value, 'refund_reference' => $reference, 'refunded_at' => now(), 'updated_at' => now(),
            ]),
            default => null,
        };
    }
}
