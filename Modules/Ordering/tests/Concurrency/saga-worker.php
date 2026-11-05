<?php

declare(strict_types=1);

use Modules\Ordering\Jobs\ChargePayment;
use Modules\Ordering\Jobs\ConfirmOrder;
use Tests\Support\Race;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Modes: charge <order> | confirm <order>. Runs the job BODY without its overlap lock, so only the
// database guards (unique payment row, conditional updates, gateway idempotency key) stand between
// the duplicates: that is what at-least-once delivery after a lock expiry looks like.
Race::worker($argv, function (array $arguments): array {
    [$mode, $orderId] = $arguments;
    config([
        'payment.fake.latency_min_ms' => 0, 'payment.fake.latency_max_ms' => 20, 'payment.fake.failure_rate' => 0.0,
        'payment.fake.timeout_rate' => 0.0, 'payment.fake.timeout_ms' => 0, 'payment.fake.seed' => 'saga-race',
    ]);
    $job = $mode === 'charge' ? new ChargePayment($orderId) : new ConfirmOrder($orderId);
    app()->call([$job, 'handle']);

    return ['mode' => $mode];
});
