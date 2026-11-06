<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Models\User;
use Modules\Shared\Contracts\ConcurrencyLimiter;
use Modules\Shared\RateLimiting\InMemoryConcurrencyLimiter;
use Tests\Support\CatalogTesting;
use Tests\Support\IdentityTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
    RateLimitTesting::exact(concurrency: 1);
    Route::middleware(['api', 'auth:api', 'concurrency.cap:orders'])->post('/api/v1/_t/cap', function (): \Illuminate\Http\JsonResponse {
        // Report how many slots are held WHILE the request is inside the cap.
        return response()->json(['in_flight' => app(ConcurrencyLimiter::class)->inFlight('cc-orders|user:'.request()->user('api')->getAuthIdentifier())]);
    });
    Route::middleware(['api', 'auth:api', 'concurrency.cap:orders'])->post('/api/v1/_t/cap-boom', fn () => throw new RuntimeException('handler crashed'));
});

it('holds exactly one slot during the request and releases it afterwards', function (): void {
    $token = CatalogTesting::token('customer');
    $this->withToken($token)->postJson('/api/v1/_t/cap')->assertOk()->assertJsonPath('in_flight', 1);
    $this->withToken($token)->postJson('/api/v1/_t/cap')->assertOk()->assertJsonPath('in_flight', 1);
});

it('answers 429 concurrency-limit-exceeded while another request of the same user is in flight', function (): void {
    $user = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
    $token = IdentityTesting::access($user);
    // Another request of the same user is "in flight": its slot is held by a different member id.
    expect(RateLimitTesting::memorySemaphore()->acquire('cc-orders|user:'.$user->id, 'other-request', 1, 30_000))->toBeTrue();

    $response = $this->withToken($token)->postJson('/api/v1/_t/cap')->assertStatus(429)
        ->assertHeader('Retry-After')->assertHeader('RateLimit-Limit', '1')->assertHeader('RateLimit-Remaining', '0');
    expect($response->json('type'))->toEndWith('/problems/concurrency-limit-exceeded');

    // Another user is not affected.
    $this->withToken(CatalogTesting::token('customer'))->postJson('/api/v1/_t/cap')->assertOk();
});

it('releases the slot when the handler throws', function (): void {
    $token = CatalogTesting::token('customer');
    $this->withoutExceptionHandling();
    expect(fn () => $this->withToken($token)->postJson('/api/v1/_t/cap-boom'))->toThrow(RuntimeException::class);
    $this->withExceptionHandling();
    $this->withToken($token)->postJson('/api/v1/_t/cap')->assertOk();
});

it('frees a leaked slot once its expiry passes', function (): void {
    $now = 5_000_000;
    $semaphore = new InMemoryConcurrencyLimiter(function () use (&$now): int {
        return $now;
    });
    expect($semaphore->acquire('k', 'crashed-worker', 1, 1_000))->toBeTrue();
    expect($semaphore->acquire('k', 'next', 1, 1_000))->toBeFalse();
    $now += 1_001;
    expect($semaphore->acquire('k', 'next', 1, 1_000))->toBeTrue();
    $semaphore->release('k', 'crashed-worker'); // idempotent, touches only its own (already gone) member
    expect($semaphore->inFlight('k'))->toBe(1);
});

it('fails closed with 503 when the semaphore backend is down', function (): void {
    RateLimitTesting::memorySemaphore()->failWith('down');
    $this->withToken(CatalogTesting::token('customer'))->postJson('/api/v1/_t/cap')->assertStatus(503);
});
