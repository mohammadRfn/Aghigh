<?php

declare(strict_types=1);

namespace Modules\Ordering\Saga;

use Modules\Ordering\Jobs\CompensateOrder;
use Throwable;

/**
 * Bus::chain()->catch() callback. An invokable object instead of a closure: serialized into the chain
 * payload as plain data (ids only) and unit-testable without a queue.
 */
final readonly class CompensateOnChainFailure
{
    public function __construct(public string $orderId) {}

    public function __invoke(?Throwable $error = null): void
    {
        CompensateOrder::dispatch($this->orderId, 'chain-failed');
    }
}
