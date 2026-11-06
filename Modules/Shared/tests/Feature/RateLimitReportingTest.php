<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\Shared\Events\RateLimitHit;
use Modules\Shared\RateLimiting\RateLimitKey;
use Monolog\Handler\TestHandler;
use Tests\Support\RateLimitTesting;

beforeEach(function (): void {
    RateLimitTesting::exact(['public-catalog' => 1]);
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('security');
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/log', fn () => response()->json(['ok' => true]))->name('t.log');
});

function securityRecords(): array
{
    /** @var TestHandler $handler */
    $handler = Log::channel('security')->getLogger()->getHandlers()[0];

    return array_values(array_filter($handler->getRecords(), fn ($r): bool => $r['message'] === 'rate_limit_hit'));
}

it('logs rate_limit_hit on the security channel with fixed fields and a hashed key, and dispatches RateLimitHit', function (): void {
    Event::fake([RateLimitHit::class]);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->getJson('/api/v1/_t/log')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->getJson('/api/v1/_t/log')->assertStatus(429);

    $records = securityRecords();
    expect($records)->toHaveCount(1);
    $context = $records[0]['context'];
    $expectedHash = RateLimitKey::hash('public-catalog|ip:198.51.100.3');
    expect($context)->toMatchArray(['limiter' => 'public-catalog', 'key_hash' => $expectedHash, 'ip' => '198.51.100.3', 'route' => 't.log', 'driver' => 'laravel', 'suppressed' => 0])
        ->and($context['key_hash'])->toHaveLength(16)
        ->and($context['key_hash'])->not->toBe(hash('sha256', '198.51.100.3'))
        ->and(json_encode($context))->not->toContain('public-catalog|ip:');

    Event::assertDispatched(RateLimitHit::class, fn (RateLimitHit $e): bool => $e->limiter === 'public-catalog' && $e->keyHash === $expectedHash && $e->route === 't.log' && $e->retryAfterSeconds >= 1);
});

it('never logs a plain hash of an email for login keys', function (): void {
    $key = RateLimitKey::for('auth-login', 'ip+email', \Illuminate\Http\Request::create('/x', 'POST', ['email' => 'Victim@Example.com']));
    expect($key)->not->toContain('victim')->not->toContain('Victim')
        ->and($key)->not->toContain(hash('sha256', 'victim@example.com'))
        ->and($key)->toContain(RateLimitKey::emailTag('victim@example.com'))
        ->and(RateLimitKey::hash($key))->toHaveLength(16);
});

it('samples the log above N hits per key per window and reports the suppressed count', function (): void {
    config(['rate-limits.log.sample_after' => 3, 'rate-limits.log.sample_every' => 5]);
    Event::fake([RateLimitHit::class]);

    foreach (range(1, 14) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->getJson('/api/v1/_t/log');
    }
    // 13 denied hits: 1..3 logged individually, then every 5th over the threshold (8th and 13th) with suppressed=4.
    $records = securityRecords();
    expect($records)->toHaveCount(5)
        ->and(array_map(fn ($r): int => $r['context']['suppressed'], $records))->toBe([0, 0, 0, 4, 4]);
    Event::assertDispatchedTimes(RateLimitHit::class, 13); // events are never sampled
});
