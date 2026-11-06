<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IdentityTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    IdentityTesting::configure();
    config(['payment.webhook.secret' => 'whsec_current', 'payment.webhook.tolerance_seconds' => 300]);
    RateLimitTesting::exact(['webhook' => 2]);
});

function postLimitedWebhook(string $ip): \Illuminate\Testing\TestResponse
{
    $body = json_encode(['id' => 'evt_'.bin2hex(random_bytes(4)), 'type' => 'payment.captured', 'data' => ['order_id' => '00000000-0000-7000-8000-000000000000']], JSON_THROW_ON_ERROR);
    $t = time();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->call('POST', '/api/v1/payments/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'whsec_current'),
    ], $body);
}

it('limits the webhook per IP, counts before signature verification, and stays open for other IPs', function (): void {
    expect(postLimitedWebhook('192.0.2.10')->status())->not->toBe(429);
    expect(postLimitedWebhook('192.0.2.10')->status())->not->toBe(429);
    $limited = postLimitedWebhook('192.0.2.10');
    expect($limited->status())->toBe(429)->and($limited->json('type'))->toEndWith('/problems/too-many-requests');
    $limited->assertHeader('Retry-After')->assertHeader('RateLimit-Limit', '2');
    expect(postLimitedWebhook('192.0.2.11')->status())->not->toBe(429);
});
