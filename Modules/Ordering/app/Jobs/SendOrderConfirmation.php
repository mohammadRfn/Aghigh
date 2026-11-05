<?php

declare(strict_types=1);

namespace Modules\Ordering\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Ordering\Mail\OrderConfirmedMail;
use Modules\Ordering\Models\Order;
use Modules\Shared\Queue\Backoff;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Modules\Shared\Queue\Queues;
use Throwable;

/** SMTP work on the notifications queue. Id-only payload: the address is resolved at send time. */
final class SendOrderConfirmation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue(Queues::NOTIFICATIONS);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(WithoutOverlappingOnState::store());
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return Backoff::exponential(10, $this->tries - 1, 300);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlappingOnState('ordering:mail:'.$this->orderId))->releaseAfter(10)->expireAfter($this->timeout + 15)];
    }

    public function handle(UserDirectory $users): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->confirmed_at === null) {
            return;
        }
        $email = $users->emailOf($order->user_id);
        if ($email === null) {
            return; // the account is gone: nobody to notify
        }
        Mail::to($email)->send(new OrderConfirmedMail($order->id));
    }

    public function failed(?Throwable $exception): void
    {
        // The order is confirmed and stays confirmed: a lost notification is never compensated.
        Log::warning('order_confirmation_mail_failed', ['order_id' => $this->orderId, 'exception' => $exception === null ? null : $exception::class]);
    }
}
