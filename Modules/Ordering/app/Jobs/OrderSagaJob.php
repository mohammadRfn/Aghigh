<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Ordering\Exceptions\SagaStepRejected;
use Modules\Shared\Queue\Backoff;
use Modules\Shared\Queue\Middleware\FailWithoutRetry;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Modules\Shared\Queue\Queues;
use Throwable;

/**
 * Common contract of every per-order saga job: id-only payload, orders queue, afterCommit, explicit
 * tries / maxExceptions / timeout, exponential backoff, one lock per ORDER shared by all saga jobs
 * (no two steps of one order run at once), and failed() hands the order to compensation.
 * Releases caused by the lock or the gateway throttle count toward $tries but not $maxExceptions.
 */
abstract class OrderSagaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue(Queues::ORDERS);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return Backoff::exponential(
            (int) config('ordering.saga.backoff_base_seconds', 5),
            $this->tries - 1,
            (int) config('ordering.saga.backoff_cap_seconds', 120),
        );
    }

    /** @return list<object> outermost first */
    public function middleware(): array
    {
        return [new FailWithoutRetry([SagaStepRejected::class]), self::orderLock($this->orderId, $this->timeout)];
    }

    public static function orderLock(string $orderId, int $timeout): WithoutOverlappingOnState
    {
        // expireAfter > timeout: a worker killed mid-job leaves a lock that frees itself.
        return (new WithoutOverlappingOnState('ordering:order:'.$orderId))->shared()->releaseAfter(5)->expireAfter($timeout + 15);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['order:'.$this->orderId];
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('order_saga_step_failed', ['order_id' => $this->orderId, 'job' => class_basename(static::class), 'exception' => $exception === null ? null : $exception::class]);
        CompensateOrder::dispatch($this->orderId, 'step-failed:'.class_basename(static::class));
    }
}
