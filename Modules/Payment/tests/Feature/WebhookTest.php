<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    IdentityTesting::configure(); // array state store for the replay markers
    config([
        'payment.webhook.secret' => 'whsec_current',
        'payment.webhook.previous_secret' => 'whsec_previous',
        'payment.webhook.tolerance_seconds' => 300,
    ]);
});

function webhookBody(string $eventId, string $type, string $orderId, array $data = []): string
{
    return json_encode(['id' => $eventId, 'type' => $type, 'data' => ['order_id' => $orderId] + $data], JSON_THROW_ON_ERROR);
}

function webhookSignature(string $body, string $secret = 'whsec_current', ?int $timestamp = null): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

function postWebhook(string $body, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_X_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/v1/payments/webhook', [], [], [], $server, $body);
}

function pendingPayment(string $orderId): void
{
    DB::table('payments')->insert([
        'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'amount_minor' => 1000, 'currency' => 'IRR',
        'status' => 'pending', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('applies a signed event once, without any JWT', function (): void {
    $order = (string) Str::uuid7();
    pendingPayment($order);
    $body = webhookBody('evt_1', 'charge.succeeded', $order, ['reference' => 'ch_ref_1']);

    postWebhook($body, webhookSignature($body))->assertOk()->assertExactJson(['received' => true, 'duplicate' => false]);

    expect(DB::table('payments')->where('order_id', $order)->first(['status', 'gateway_reference']))->toEqual((object) ['status' => 'succeeded', 'gateway_reference' => 'ch_ref_1'])
        ->and(DB::table('payment_webhook_events')->where('event_id', 'evt_1')->count())->toBe(1)
        ->and(Route::getRoutes()->getByName('api.v1.payment.webhook')?->gatherMiddleware())->not->toContain('auth:api');
});

it('answers every verification failure with the same 401 problem and stores nothing', function (string $case): void {
    $order = (string) Str::uuid7();
    pendingPayment($order);
    $body = webhookBody('evt_bad', 'charge.succeeded', $order);
    $response = match ($case) {
        'bad signature' => postWebhook($body, webhookSignature($body, 'whsec_wrong')),
        'old timestamp' => postWebhook($body, webhookSignature($body, 'whsec_current', time() - 301)),
        'future timestamp' => postWebhook($body, webhookSignature($body, 'whsec_current', time() + 301)),
        'tampered body' => postWebhook(webhookBody('evt_bad', 'charge.succeeded', $order, ['reference' => 'x']), webhookSignature($body)),
        'missing header' => postWebhook($body, null),
        'malformed header' => postWebhook($body, 'v1=deadbeef'),
        'malformed payload' => postWebhook('{"id": 1}', webhookSignature('{"id": 1}')),
    };

    $response->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json');
    expect($response->json('detail'))->toBe('Authentication is required.')
        ->and($response->json('status'))->toBe(401)
        ->and(str_ends_with((string) $response->json('type'), '/problems/unauthenticated'))->toBeTrue()
        ->and(array_keys($response->json()))->not->toContain('reason')
        ->and(DB::table('payment_webhook_events')->count())->toBe(0)
        ->and(DB::table('payments')->where('order_id', $order)->value('status'))->toBe('pending');
})->with(['bad signature', 'old timestamp', 'future timestamp', 'tampered body', 'missing header', 'malformed header', 'malformed payload']);

it('rejects a replayed request but treats a re-signed delivery of the same event as a duplicate', function (): void {
    $order = (string) Str::uuid7();
    pendingPayment($order);
    $body = webhookBody('evt_replay', 'charge.failed', $order, ['code' => 'card_declined']);
    $signature = webhookSignature($body);

    postWebhook($body, $signature)->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($body, $signature)->assertStatus(401);
    postWebhook($body, webhookSignature($body, 'whsec_current', time() + 1))->assertOk()->assertJson(['duplicate' => true]);

    expect(DB::table('payment_webhook_events')->count())->toBe(1)
        ->and(DB::table('payments')->where('order_id', $order)->first(['status', 'failure_code']))->toEqual((object) ['status' => 'failed', 'failure_code' => 'card_declined']);
});

it('accepts the previous secret during rotation, alone or next to the new signature', function (): void {
    $order = (string) Str::uuid7();
    pendingPayment($order);
    $first = webhookBody('evt_rot_1', 'unknown.type', $order);
    $second = webhookBody('evt_rot_2', 'charge.succeeded', $order);
    $timestamp = time();
    $both = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$second, 'whsec_unknown').',v1='.hash_hmac('sha256', $timestamp.'.'.$second, 'whsec_previous');

    postWebhook($first, webhookSignature($first, 'whsec_previous'))->assertOk();
    postWebhook($second, $both)->assertOk();
    config(['payment.webhook.previous_secret' => '']);
    $third = webhookBody('evt_rot_3', 'charge.succeeded', $order);
    postWebhook($third, webhookSignature($third, 'whsec_previous'))->assertStatus(401);

    expect(DB::table('payment_webhook_events')->count())->toBe(2)
        ->and(DB::table('payments')->where('order_id', $order)->value('status'))->toBe('succeeded');
});
