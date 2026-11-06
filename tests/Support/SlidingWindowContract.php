<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Modules\Shared\Contracts\SlidingWindowLimiter;

/**
 * ONE contract test for both SlidingWindowLimiter implementations. Feature suite: in-memory;
 * Concurrency suite: Redis Lua. Each case is a closure(limiter, key) using the explicit test clock.
 *
 * @return array<string, Closure(SlidingWindowLimiter, string): void>
 */
final class SlidingWindowContract
{
    public static function cases(): array
    {
        $t0 = 1_800_000_000_000; // arbitrary epoch ms

        return [
            'burst: exactly `limit` attempts pass, the next one is denied with Retry-After' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                foreach (range(1, 3) as $i) {
                    $r = $l->attempt($key, 3, 10, $t0 + $i);
                    expect($r->allowed)->toBeTrue()->and($r->limit)->toBe(3)->and($r->remaining)->toBe(3 - $i);
                }
                $denied = $l->attempt($key, 3, 10, $t0 + 4);
                expect($denied->allowed)->toBeFalse()
                    ->and($denied->remaining)->toBe(0)
                    ->and($denied->retryAfterMs)->toBe(10_000 - 3) // oldest (t0+1) + window - now (t0+4)
                    ->and($denied->retryAfterSeconds())->toBe(10)
                    ->and($denied->resetAtMs)->toBe($t0 + 1 + 10_000)
                    ->and($denied->nowMs)->toBe($t0 + 4);
            },
            'steady rate below the limit is never denied' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                // 5 per 1s window, one every 250ms -> at most 4 in any window
                foreach (range(0, 39) as $i) {
                    expect($l->attempt($key, 5, 1, $t0 + $i * 250)->allowed)->toBeTrue();
                }
            },
            'window slides: capacity returns as old attempts age out, not all at once' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                $l->attempt($key, 2, 10, $t0);
                $l->attempt($key, 2, 10, $t0 + 5_000);
                expect($l->attempt($key, 2, 10, $t0 + 9_999)->allowed)->toBeFalse();
                expect($l->attempt($key, 2, 10, $t0 + 10_001)->allowed)->toBeTrue();   // first one aged out
                expect($l->attempt($key, 2, 10, $t0 + 10_002)->allowed)->toBeFalse();  // second still inside
                expect($l->attempt($key, 2, 10, $t0 + 15_001)->allowed)->toBeTrue();
            },
            'reset: after a full idle window the key is empty again' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                foreach (range(1, 3) as $i) {
                    $l->attempt($key, 3, 2, $t0 + $i);
                }
                expect($l->attempt($key, 3, 2, $t0 + 10)->allowed)->toBeFalse();
                $fresh = $l->attempt($key, 3, 2, $t0 + 2_100);
                expect($fresh->allowed)->toBeTrue()->and($fresh->remaining)->toBe(2);
            },
            'many keys are independent' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                foreach (range(1, 20) as $k) {
                    expect($l->attempt($key.':'.$k, 1, 60, $t0)->allowed)->toBeTrue();
                    expect($l->attempt($key.':'.$k, 1, 60, $t0 + 1)->allowed)->toBeFalse();
                }
                expect($l->attempt($key.':fresh', 1, 60, $t0 + 2)->allowed)->toBeTrue();
            },
            'denied attempts do not extend the penalty (no member is added)' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                $l->attempt($key, 1, 5, $t0);
                foreach (range(1, 50) as $i) {
                    $l->attempt($key, 1, 5, $t0 + $i * 50);
                }
                expect($l->attempt($key, 1, 5, $t0 + 5_001)->allowed)->toBeTrue();
            },
            'clear() empties the key' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                $l->attempt($key, 1, 60, $t0);
                expect($l->attempt($key, 1, 60, $t0 + 1)->allowed)->toBeFalse();
                $l->clear($key);
                expect($l->attempt($key, 1, 60, $t0 + 2)->allowed)->toBeTrue();
            },
            'a limit below 1 is treated as 1 (never disabled)' => function (SlidingWindowLimiter $l, string $key) use ($t0): void {
                expect($l->attempt($key, 0, 60, $t0)->allowed)->toBeTrue();
                expect($l->attempt($key, 0, 60, $t0 + 1)->allowed)->toBeFalse();
            },
        ];
    }
}
