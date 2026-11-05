<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payment\Contracts\PaymentCharger;
use Modules\Payment\Contracts\PaymentLedger;
use Modules\Payment\Contracts\PaymentStatus;
use Modules\Payment\Exceptions\GatewayTimeout;
use Modules\Payment\Gateways\FakeGateway;
use Modules\Payment\Gateways\GatewayRequest;
use Modules\Payment\Gateways\PaymentGateway;
use Tests\Support\IdentityTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    IdentityTesting::configure();
    config(['payment.fake' => [
        'latency_min_ms' => 0, 'latency_max_ms' => 0, 'failure_rate' => 0.0, 'timeout_rate' => 0.0,
        'timeout_ms' => 0, 'seed' => 'fixed-seed', 'ledger_store' => 'state', 'ledger_ttl_seconds' => 3600,
    ]]);
});

/** @param list<string> $keys */
function decisions(array $keys): array
{
    Cache::store('state')->flush();
    $gateway = app(PaymentGateway::class);
    $out = [];
    foreach ($keys as $key) {
        try {
            $out[$key] = $gateway->charge(new GatewayRequest($key, 1000, 'IRR', 1))->approved ? 'approved' : 'declined';
        } catch (GatewayTimeout) {
            $out[$key] = 'timeout';
        }
    }
    ksort($out);

    return $out;
}

it('makes the same decisions for the same seed whatever the call order', function (): void {
    config(['payment.fake.failure_rate' => 0.3, 'payment.fake.timeout_rate' => 0.2, 'payment.fake.seed' => 'seed-A']);
    $keys = array_map(fn (int $i): string => sprintf('00000000-0000-7000-8000-%012d', $i), range(1, 80));

    $first = decisions($keys);
    $reversed = decisions(array_reverse($keys));
    config(['payment.fake.seed' => 'seed-B']);
    $otherSeed = decisions($keys);

    expect($reversed)->toBe($first)
        ->and(array_keys(array_count_values($first)))->toContain('approved', 'declined', 'timeout')
        ->and($otherSeed)->not->toBe($first);
});

it('honours the magic amounts deterministically', function (): void {
    $gateway = app(PaymentGateway::class);

    expect($gateway->charge(new GatewayRequest('k-402', FakeGateway::DECLINE_AMOUNT, 'IRR'))->approved)->toBeFalse()
        ->and(fn () => $gateway->charge(new GatewayRequest('k-504', FakeGateway::TIMEOUT_AMOUNT, 'IRR', 1)))->toThrow(GatewayTimeout::class)
        ->and(fn () => $gateway->charge(new GatewayRequest('k-504', FakeGateway::TIMEOUT_AMOUNT, 'IRR', 2)))->toThrow(GatewayTimeout::class)
        ->and($gateway->find('k-504'))->toBeNull()
        ->and(fn () => $gateway->charge(new GatewayRequest('k-408', FakeGateway::TIMEOUT_AFTER_CAPTURE_AMOUNT, 'IRR', 1)))->toThrow(GatewayTimeout::class);

    $replay = $gateway->charge(new GatewayRequest('k-408', FakeGateway::TIMEOUT_AFTER_CAPTURE_AMOUNT, 'IRR', 2));
    expect($replay->approved)->toBeTrue()->and($gateway->find('k-408')?->reference)->toBe($replay->reference);
});

it('charges an order once, using the order id as the idempotency key', function (): void {
    $charger = app(PaymentCharger::class);
    $order = (string) Str::uuid7();

    $first = $charger->charge($order, 1000, 'IRR');
    $second = $charger->charge($order, 1000, 'IRR');

    expect($first->succeeded())->toBeTrue()
        ->and($second->reference)->toBe($first->reference)
        ->and(DB::table('payments')->where('order_id', $order)->count())->toBe(1)
        ->and((int) DB::table('payments')->where('order_id', $order)->value('attempts'))->toBe(1)
        ->and(app(PaymentGateway::class)->find($order)?->reference)->toBe($first->reference)
        ->and(app(PaymentLedger::class)->statuses([$order, (string) Str::uuid7()]))->toBe([$order => PaymentStatus::Succeeded])
        ->and(fn () => $charger->charge($order, 999, 'IRR'))->toThrow(LogicException::class);
});

it('reconciles ambiguous timeouts on refund: captured is refunded, never captured is voided', function (): void {
    $charger = app(PaymentCharger::class);
    $captured = (string) Str::uuid7();
    $never = (string) Str::uuid7();

    expect(fn () => $charger->charge($captured, FakeGateway::TIMEOUT_AFTER_CAPTURE_AMOUNT, 'IRR'))->toThrow(GatewayTimeout::class)
        ->and(fn () => $charger->charge($never, FakeGateway::TIMEOUT_AMOUNT, 'IRR'))->toThrow(GatewayTimeout::class)
        ->and(app(PaymentLedger::class)->status($captured))->toBe(PaymentStatus::Pending);

    expect($charger->refund($captured)->status)->toBe(PaymentStatus::Refunded)
        ->and($charger->refund($captured)->status)->toBe(PaymentStatus::Refunded)
        ->and($charger->refund($never)->status)->toBe(PaymentStatus::Failed)
        ->and(DB::table('payments')->where('order_id', $never)->value('failure_code'))->toBe('voided')
        ->and($charger->refund((string) Str::uuid7())->status)->toBeNull();
});
