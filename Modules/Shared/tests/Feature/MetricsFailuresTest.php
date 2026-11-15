<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\Shared\Metrics\Failures;
use Modules\Shared\Metrics\Names;
use Modules\Shared\Metrics\PrometheusMetrics;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

beforeEach(function (): void {
    Failures::reset();
});

afterEach(function (): void {
    Failures::reset();
});

it('counts every failure but logs at most once a minute, with the suppressed count', function (): void {
    Log::shouldReceive('channel')->with('app')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'metrics_write_failed'
        && $context['driver'] === 'redis' && $context['error'] === RuntimeException::class
        && $context['suppressed'] === 0 && $context['pending'] === 1 && mb_strlen($context['message']) <= 200);

    for ($i = 0; $i < 5; $i++) {
        Failures::record('redis', new RuntimeException(str_repeat('x', 500)));
    }

    expect(Failures::pending())->toBe(5)->and(Failures::total())->toBe(5);
});

it('take() returns and zeroes the pending count and restore() puts it back', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning');
    Failures::record('redis', new RuntimeException('a'));
    Failures::record('redis', new RuntimeException('b'));

    expect(Failures::take())->toBe(2)->and(Failures::pending())->toBe(0)->and(Failures::total())->toBe(2);

    Failures::restore(2);
    expect(Failures::pending())->toBe(2);

    Failures::reset();
    expect(Failures::pending())->toBe(0)->and(Failures::total())->toBe(0);
});

it('survives a broken logger', function (): void {
    Log::shouldReceive('channel')->andThrow(new RuntimeException('logger down'));

    expect(fn () => Failures::record('redis', new RuntimeException('x')))->not->toThrow(Throwable::class)
        ->and(Failures::total())->toBe(1);
});

it('PrometheusMetrics records a storage outage, then flushes the failures once storage answers again', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning');

    $storage = new InMemory;
    $down = true;
    $resets = 0;
    $metrics = new PrometheusMetrics(
        storage: function () use (&$down, $storage): Adapter {
            if ($down) {
                throw new RuntimeException('redis down');
            }

            return $storage;
        },
        driver: 'testdrv',
        strict: false,
        reset: function () use (&$resets): void {
            $resets++;
        },
    );

    $metrics->counter(Names::ORDERS, ['outcome' => 'paid']); // lost, but must not throw
    expect(Failures::pending())->toBe(1)->and($resets)->toBe(1)->and($metrics->render())->toBeNull();

    $down = false;
    $metrics->counter(Names::ORDERS, ['outcome' => 'paid']);
    $body = (string) $metrics->render();

    expect($body)->toContain('aghigh_orders_total{outcome="paid"} 1')
        ->and($body)->toContain('aghigh_metrics_write_failures_total{driver="testdrv"} 2') // the write + the unreadable render
        ->and($body)->toContain('aghigh_state_store_errors_total{component="metrics"} 2')
        ->and(Failures::pending())->toBe(0);
});

it('PrometheusMetrics validates names, types and labels strictly in the memory driver and drops them otherwise', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning');

    $strict = new PrometheusMetrics(fn (): Adapter => new InMemory, 'memory', strict: true);
    expect(fn () => $strict->counter(Names::HTTP_DURATION))->toThrow(InvalidArgumentException::class) // histogram used as counter
        ->and(fn () => $strict->gauge(Names::ORDERS, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $strict->histogram(Names::ORDERS, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $strict->counter(Names::ORDERS, ['user_id' => '1']))->toThrow(InvalidArgumentException::class);

    $lenient = new PrometheusMetrics(fn (): Adapter => new InMemory, 'redis', strict: false);
    expect(fn () => $lenient->counter('aghigh_nope_total'))->not->toThrow(Throwable::class)
        ->and(Failures::total())->toBe(1);
});

it('PrometheusMetrics ignores non-positive increments and sanitises label values', function (): void {
    $metrics = new PrometheusMetrics(fn (): Adapter => new InMemory, 'memory', strict: true);

    $metrics->counter(Names::ORDERS, ['outcome' => 'zero'], 0);
    $metrics->counter(Names::ORDERS, ['outcome' => 'negative'], -3);
    $metrics->counter(Names::ORDERS, ['outcome' => "a \"quoted\"\nvalue"], 2);
    $metrics->counter(Names::CHAT_CLAIMS);
    $body = (string) $metrics->render();

    expect($body)->not->toContain('outcome="zero"')->and($body)->not->toContain('outcome="negative"')
        ->and($body)->toContain('aghigh_orders_total{outcome="a_quoted_value"} 2')
        ->and($body)->toContain('aghigh_chat_claims_total 1');
});

it('PrometheusMetrics wipe() empties the store', function (): void {
    $metrics = new PrometheusMetrics(fn (): Adapter => new InMemory, 'memory', strict: true);
    $metrics->counter(Names::ORDERS, ['outcome' => 'paid']);
    expect((string) $metrics->render())->toContain('aghigh_orders_total');

    $metrics->wipe();

    expect((string) $metrics->render())->not->toContain('aghigh_orders_total');
});
