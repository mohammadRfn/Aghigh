<?php

declare(strict_types=1);

namespace Modules\Payment\Providers;

use InvalidArgumentException;
use Modules\Payment\Contracts\PaymentCharger;
use Modules\Payment\Contracts\PaymentLedger;
use Modules\Payment\Gateways\FakeGateway;
use Modules\Payment\Gateways\PaymentGateway;
use Modules\Payment\Services\PaymentService;
use Nwidart\Modules\Support\ModuleServiceProvider;

final class PaymentServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Payment';

    protected string $nameLower = 'payment';

    /** @var string[] */
    protected array $providers = [EventServiceProvider::class, RouteServiceProvider::class];

    public function register(): void
    {
        parent::register();
        // Resolved per use so tests and Concurrency workers can switch config at runtime.
        $this->app->bind(PaymentGateway::class, static fn (): PaymentGateway => match ((string) config('payment.gateway')) {
            'fake' => new FakeGateway((string) config('payment.fake.ledger_store', 'state'), (array) config('payment.fake', [])),
            default => throw new InvalidArgumentException('PAYMENT_GATEWAY must be: fake.'),
        });
        $this->app->bind(PaymentCharger::class, PaymentService::class);
        $this->app->bind(PaymentLedger::class, PaymentService::class);
    }

    public function boot(): void
    {
        parent::boot();
        foreach (['failure_rate', 'timeout_rate'] as $rate) {
            $value = (float) config('payment.fake.'.$rate);
            if ($value < 0.0 || $value > 1.0) {
                throw new InvalidArgumentException('payment.fake.'.$rate.' must be between 0 and 1.');
            }
        }
        if ((int) config('payment.fake.latency_min_ms') > (int) config('payment.fake.latency_max_ms')) {
            throw new InvalidArgumentException('PAYMENT_FAKE_LATENCY_MIN_MS must not exceed PAYMENT_FAKE_LATENCY_MAX_MS.');
        }
    }
}
