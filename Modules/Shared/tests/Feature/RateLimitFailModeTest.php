<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\Shared\Events\RateLimitBackendFailed;
use Tests\Support\RateLimitTesting;

beforeEach(function (): void {
    RateLimitTesting::exact(['public-catalog' => 5, 'auth-login' => 5, 'orders' => 5]);
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/open', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'throttle.sliding:auth-login'])->post('/api/v1/_t/closed', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'throttle.sliding:orders'])->get('/api/v1/_t/sliding-closed', fn () => response()->json(['ok' => true]));
});

/** Make Laravel's RateLimiter (the `laravel` driver) blow up like a dead Redis. */
function breakLaravelLimiter(): void
{
    app()->instance(RateLimiter::class, new class(app('cache')->store('array')) extends RateLimiter
    {
        public function tooManyAttempts($key, $maxAttempts)
        {
            throw new RuntimeException('Connection refused'); // what a dead state Redis looks like to the caller
        }
    });
}

it('fails OPEN for the public catalog: request served, no headers, backend event dispatched', function (): void {
    Event::fake([RateLimitBackendFailed::class]);
    breakLaravelLimiter();

    $this->getJson('/api/v1/_t/open')->assertOk()->assertHeaderMissing('RateLimit-Limit');

    Event::assertDispatched(RateLimitBackendFailed::class, fn (RateLimitBackendFailed $e): bool => $e->limiter === 'public-catalog' && $e->failMode === 'open');
});

it('fails CLOSED for auth: 503 rate-limit-unavailable problem with Retry-After', function (): void {
    Event::fake([RateLimitBackendFailed::class]);
    breakLaravelLimiter();

    $response = $this->postJson('/api/v1/_t/closed', ['email' => 'a@example.com'])->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')->assertHeader('Retry-After')
        ->assertJsonPath('limiter', 'auth-login');
    expect($response->json('type'))->toEndWith('/problems/rate-limit-unavailable');

    Event::assertDispatched(RateLimitBackendFailed::class, fn (RateLimitBackendFailed $e): bool => $e->limiter === 'auth-login' && $e->failMode === 'closed');
});

it('fails CLOSED for the sliding orders limiter when its backend throws', function (): void {
    Event::fake([RateLimitBackendFailed::class]);
    RateLimitTesting::memorySliding()->failWith('READONLY You can\'t write against a read only replica.');

    $this->getJson('/api/v1/_t/sliding-closed')->assertStatus(503);
    Event::assertDispatched(RateLimitBackendFailed::class, fn (RateLimitBackendFailed $e): bool => $e->limiter === 'orders');

    RateLimitTesting::memorySliding()->failWith(null);
    $this->getJson('/api/v1/_t/sliding-closed')->assertOk()->assertHeader('RateLimit-Limit', '5');
});

it('can flip a limiter to fail-open through config', function (): void {
    config(['rate-limits.limiters.orders.fail' => 'open']);
    RateLimitTesting::memorySliding()->failWith('boom');

    $this->getJson('/api/v1/_t/sliding-closed')->assertOk();
});
