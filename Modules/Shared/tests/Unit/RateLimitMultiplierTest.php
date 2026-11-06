<?php

declare(strict_types=1);

use Modules\Shared\RateLimiting\RateLimitMultiplier;

it('honours the multiplier only in local, testing and loadtest', function (string $environment, float $expected): void {
    expect(RateLimitMultiplier::factor($environment, '20'))->toBe($expected);
})->with([
    ['local', 20.0], ['testing', 20.0], ['loadtest', 20.0],
    ['production', 1.0], ['staging', 1.0], ['', 1.0],
]);

it('scales, never disables', function (): void {
    expect(RateLimitMultiplier::scale(10, 2.5))->toBe(25)
        ->and(RateLimitMultiplier::scale(10, 0.01))->toBe(1)
        ->and(RateLimitMultiplier::factor('local', '0'))->toBe(RateLimitMultiplier::MIN)
        ->and(RateLimitMultiplier::factor('local', '999999'))->toBe(RateLimitMultiplier::MAX)
        ->and(RateLimitMultiplier::factor('local', null))->toBe(1.0)
        ->and(RateLimitMultiplier::factor('local', ''))->toBe(1.0);
});

it('fails the boot check when set outside the allowed environments', function (): void {
    RateLimitMultiplier::assertAllowed('production', null);
    RateLimitMultiplier::assertAllowed('production', '');
    RateLimitMultiplier::assertAllowed('loadtest', '20');
    expect(fn () => RateLimitMultiplier::assertAllowed('production', '20'))->toThrow(RuntimeException::class, 'RATE_LIMIT_MULTIPLIER');
    expect(fn () => RateLimitMultiplier::assertAllowed('local', 'lots'))->toThrow(RuntimeException::class, 'numeric');
});
