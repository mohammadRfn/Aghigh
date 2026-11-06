<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CatalogTesting::configure();
    RateLimitTesting::exact(['public-catalog' => 2, 'orders-ip' => 5, 'orders' => 2, 'broadcasting-auth' => 2]);

    // Ad-hoc routes so the shape is tested independently of any module's business rules.
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/ip', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'throttle.sliding:orders-ip', 'auth:api', 'throttle.sliding:orders'])->get('/api/v1/_t/stacked', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'auth:api', 'throttle.sliding:broadcasting-auth'])->post('/api/v1/_t/broadcast', fn () => response()->json(['ok' => true]));
});

it('answers 429 as problem+json too-many-requests with Retry-After and RateLimit headers', function (): void {
    $this->getJson('/api/v1/_t/ip')->assertOk()
        ->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1')->assertHeader('RateLimit-Reset')
        ->assertHeaderMissing('X-RateLimit-Limit')->assertHeaderMissing('X-RateLimit-Remaining');
    $this->getJson('/api/v1/_t/ip')->assertOk()->assertHeader('RateLimit-Remaining', '0');

    $response = $this->getJson('/api/v1/_t/ip')->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('RateLimit-Limit', '2')
        ->assertHeader('RateLimit-Remaining', '0')
        ->assertJsonPath('status', 429)
        ->assertJsonPath('title', 'Too Many Requests')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'request_id', 'retry_after']);

    expect($response->json('type'))->toEndWith('/problems/too-many-requests')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $response->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual((int) $response->headers->get('Retry-After'))
        ->and($response->json('retry_after'))->toBe((int) $response->headers->get('Retry-After'));
});

it('isolates per-IP keys between clients', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/api/v1/_t/ip')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/api/v1/_t/ip')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/api/v1/_t/ip')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->getJson('/api/v1/_t/ip')->assertOk()->assertHeader('RateLimit-Remaining', '1');
});

it('isolates per-user keys between users sharing one IP and reports the most restrictive limiter', function (): void {
    $alice = CatalogTesting::token('customer');
    $bob = CatalogTesting::token('customer');

    // orders-ip allows 5 per IP; orders allows 2 per user. Headers must show the tighter one.
    $this->withToken($alice)->getJson('/api/v1/_t/stacked')->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1');
    $this->withToken($alice)->getJson('/api/v1/_t/stacked')->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '0');
    $this->withToken($alice)->getJson('/api/v1/_t/stacked')->assertStatus(429)->assertHeader('RateLimit-Limit', '2');

    // Bob shares the IP: the per-IP budget (5) has 2 left, his own per-user budget is untouched (2).
    $this->withToken($bob)->getJson('/api/v1/_t/stacked')->assertOk()->assertHeader('RateLimit-Remaining', '1');
    $this->withToken($bob)->getJson('/api/v1/_t/stacked')->assertOk()->assertHeader('RateLimit-Remaining', '0');
    // Now the IP budget (5) is exhausted before auth even runs: 429 without a token.
    $this->getJson('/api/v1/_t/stacked')->assertStatus(429)->assertHeader('RateLimit-Limit', '5');
});

it('keys broadcasting auth per user AND ip', function (): void {
    $token = CatalogTesting::token('customer');
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/_t/broadcast')->assertOk();
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/_t/broadcast')->assertOk();
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/_t/broadcast')->assertStatus(429);
    // Same user from another IP: separate key.
    $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->postJson('/api/v1/_t/broadcast')->assertOk();
});

it('registers the real broadcasting auth route behind the JWT guard and the limiter', function (): void {
    $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/broadcasting/auth', 'POST'));
    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('auth:api')->toContain('throttle.sliding:broadcasting-auth')->not->toContain('web');
});

it('keeps /up exempt from every limiter', function (): void {
    foreach (range(1, 10) as $_) {
        $this->get('/up')->assertOk()->assertHeaderMissing('RateLimit-Limit');
    }
});

it('attaches the real limiters to the documented routes', function (): void {
    $expect = [
        'api.v1.auth.login' => 'throttle.sliding:auth-login',
        'api.v1.auth.register' => 'throttle.sliding:auth-register',
        'api.v1.auth.verify' => 'throttle.sliding:auth-register',
        'api.v1.auth.resend' => 'throttle.sliding:auth-email-resend',
        'api.v1.auth.refresh' => 'throttle.sliding:auth-refresh',
        'api.v1.auth.forgot' => 'throttle.sliding:auth-forgot-password',
        'api.v1.auth.reset' => 'throttle.sliding:auth-forgot-password',
        'api.v1.catalog.products.index' => 'throttle.sliding:public-catalog',
        'api.v1.catalog.admin.products.store' => 'throttle.sliding:admin',
        'api.v1.payment.webhook' => 'throttle.sliding:webhook',
        'api.v1.ordering.orders.store' => 'throttle.sliding:orders',
    ];
    foreach ($expect as $name => $middleware) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} missing");
        expect($route->gatherMiddleware())->toContain($middleware, "route {$name}");
        foreach (['throttle:login', 'throttle:register', 'throttle:forgot-password'] as $legacy) {
            expect($route->gatherMiddleware())->not->toContain($legacy);
        }
    }
});
