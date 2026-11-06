<?php

declare(strict_types=1);

namespace Modules\Ordering\Services;

use Illuminate\Http\Request;
use Modules\Shared\Contracts\SlidingWindowLimiter;
use Modules\Shared\Exceptions\LimiterUnavailable;
use Modules\Shared\Exceptions\RateLimitExceeded;
use Modules\Shared\RateLimiting\LimiterBackendUnavailable;
use Modules\Shared\RateLimiting\LimiterRegistry;
use Modules\Shared\RateLimiting\RateLimitReporter;

/**
 * Business limit "orders per minute per user" (config rate-limits.business.orders_per_minute).
 * Different from the HTTP limiter `orders`: that one counts every request on the orders routes
 * (including GET and replays) per user; this one counts order ATTEMPTS that actually reach the transaction.
 *   - called by PlaceOrder AFTER the idempotency replay check -> replays never consume budget
 *   - counted in Redis (sliding log) OUTSIDE the DB transaction -> a rollback does not refund it
 *   - an attempt rejected with 409 (out-of-stock, idempotency-in-progress) DOES consume budget: it was an attempt
 * Fail mode: closed (503 rate-limit-unavailable), like every orders limiter.
 */
final class OrderRateBudget
{
    public const LIMITER = 'business:orders-per-minute';

    public function __construct(
        private readonly SlidingWindowLimiter $limiter,
        private readonly LimiterRegistry $registry,
        private readonly RateLimitReporter $reporter,
    ) {}

    public function consume(string $userId, ?Request $request = null): void
    {
        $limit = $this->registry->business('orders_per_minute');
        $key = self::LIMITER.'|user:'.$userId;
        try {
            $result = $this->limiter->attempt($key, $limit, 60);
        } catch (LimiterBackendUnavailable $error) {
            $this->reporter->backendFailed(self::LIMITER, 'closed', $error);
            throw new LimiterUnavailable(self::LIMITER);
        }
        if ($result->allowed) {
            return;
        }
        $this->reporter->hit(self::LIMITER, $key, $request ?? request(), 'business', $result->retryAfterSeconds(), 60);
        throw RateLimitExceeded::fromResult($result, 'orders-per-minute-exceeded');
    }
}
