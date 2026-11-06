<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\RateLimitTesting;

// phpunit.xml: TRUSTED_PROXIES=172.30.0.0/24 (the compose edge network). This is the Laravel side only;
// scripts/verify-trusted-proxy.sh proves the same through the real Traefik with curl.

beforeEach(function (): void {
    RateLimitTesting::exact(['public-catalog' => 1]);
    Route::middleware(['api', 'throttle.sliding:public-catalog'])->get('/api/v1/_t/ip', fn () => response()->json(['ip' => request()->ip()]));
});

it('honours X-Forwarded-For from a trusted proxy', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2'])->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/v1/_t/ip')->assertOk()->assertJsonPath('ip', '203.0.113.9');
    // Same forwarded client -> same key -> limited; a different forwarded client -> fresh key.
    $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2'])->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/v1/_t/ip')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2'])->withHeader('X-Forwarded-For', '203.0.113.10')
        ->getJson('/api/v1/_t/ip')->assertOk()->assertJsonPath('ip', '203.0.113.10');
});

it('ignores X-Forwarded-For from an untrusted peer and keys on the real remote address', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/v1/_t/ip')->assertOk()->assertJsonPath('ip', '10.9.9.9');
    // Spoofing a different XFF does not buy a new budget.
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->withHeader('X-Forwarded-For', '203.0.113.77')
        ->getJson('/api/v1/_t/ip')->assertStatus(429);
});

it('uses the last hop appended by the trusted proxy, not the client-supplied first entry', function (): void {
    // Traefik appends the peer it saw; an attacker-supplied leading entry must not become the key.
    $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2'])->withHeader('X-Forwarded-For', '1.1.1.1, 203.0.113.9')
        ->getJson('/api/v1/_t/ip')->assertOk()->assertJsonPath('ip', '203.0.113.9');
});
