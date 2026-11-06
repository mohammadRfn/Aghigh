<?php

declare(strict_types=1);

use Modules\Shared\RateLimiting\InMemorySlidingWindowLimiter;
use Modules\Shared\RateLimiting\RateLimitResult;
use Tests\Support\SlidingWindowContract;

// The same cases run against the Lua implementation in tests/Concurrency/SlidingWindowLimiterTest.php.
foreach (SlidingWindowContract::cases() as $name => $case) {
    it('in-memory sliding window: '.$name, function () use ($case): void {
        $case(new InMemorySlidingWindowLimiter, 'contract:'.bin2hex(random_bytes(4)));
    });
}

it('uses the injected clock when no explicit now is given', function (): void {
    $now = 1_000_000;
    $limiter = new InMemorySlidingWindowLimiter(function () use (&$now): int {
        return $now;
    });
    expect($limiter->attempt('k', 1, 1)->allowed)->toBeTrue();
    expect($limiter->attempt('k', 1, 1)->allowed)->toBeFalse();
    $now += 1_001;
    expect($limiter->attempt('k', 1, 1)->allowed)->toBeTrue();
});

it('exposes header-ready seconds on the result', function (): void {
    $r = new RateLimitResult(false, 5, 0, 1_200, 10_000 + 61_000, 10_000);
    expect($r->retryAfterSeconds())->toBe(2)->and($r->resetAfterSeconds())->toBe(61);
    $ok = new RateLimitResult(true, 5, 4, 0, 10_000 + 500, 10_000);
    expect($ok->retryAfterSeconds())->toBe(0)->and($ok->resetAfterSeconds())->toBe(1)
        ->and($r->isMoreRestrictiveThan($ok))->toBeTrue()->and($ok->isMoreRestrictiveThan($r))->toBeFalse();
});
