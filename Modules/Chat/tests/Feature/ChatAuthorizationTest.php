<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Chat\Actions\ClaimNextConversation;
use Tests\Support\ChatTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ChatTesting::configure();
    $this->owner = ChatTesting::user('customer');
    $this->otherCustomer = ChatTesting::user('customer');
    $this->agent = ChatTesting::user('support_agent');
    $this->otherAgent = ChatTesting::user('support_agent');
    $this->admin = ChatTesting::user('admin');
    $this->conversation = $this->withToken($this->owner['token'])->postJson('/api/v1/conversations')->assertCreated()->json('data.id');
    app(ClaimNextConversation::class)($this->agent['actor']);
});

dataset('conversation endpoints', [
    'list messages' => ['GET', 'messages', []],
    'post message' => ['POST', 'messages', ['body' => 'x', 'client_message_id' => 'abcdefghijklmnopqrst']],
    'mark read' => ['POST', 'read', []],
    'typing' => ['POST', 'typing', []],
    'close' => ['POST', 'close', []],
    'release' => ['POST', 'release', []],
]);

it('hides a conversation from every non-participant with the same 404 (BOLA)', function (string $method, string $path, array $body): void {
    foreach (['otherCustomer', 'otherAgent', 'admin'] as $who) {
        $this->withToken($this->{$who}['token'])->json($method, "/api/v1/conversations/{$this->conversation}/{$path}", $body)
            ->assertNotFound();
        // an unknown id answers exactly like a foreign one: no existence leak
        $this->withToken($this->{$who}['token'])->json($method, '/api/v1/conversations/'.Str::uuid7()."/{$path}", $body)
            ->assertNotFound();
    }
})->with('conversation endpoints');

it('answers 401 to anonymous callers on every chat endpoint', function (string $method, string $path, array $body): void {
    $response = $this->json($method, "/api/v1/conversations/{$this->conversation}/{$path}", $body)->assertUnauthorized();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
})->with('conversation endpoints');

it('answers 404 to malformed conversation ids', function (): void {
    $this->withToken($this->owner['token'])->getJson('/api/v1/conversations/not-a-uuid/messages')->assertNotFound();
});

it('enforces function-level roles', function (): void {
    $this->withToken($this->agent['token'])->postJson('/api/v1/conversations')->assertForbidden();
    $this->withToken($this->admin['token'])->postJson('/api/v1/conversations')->assertForbidden();
    $this->withToken($this->owner['token'])->postJson('/api/v1/support/queue/claim')->assertForbidden();
    $this->withToken($this->admin['token'])->postJson('/api/v1/support/queue/claim')->assertForbidden();
    $this->withToken($this->owner['token'])->postJson("/api/v1/conversations/{$this->conversation}/release")->assertForbidden();
    $this->postJson('/api/v1/conversations')->assertUnauthorized();
    $this->postJson('/api/v1/support/queue/claim')->assertUnauthorized();
    $this->postJson('/api/v1/presence/heartbeat')->assertUnauthorized();
});

it('lists only own (customer) or assigned (agent) conversations; admins see none by default', function (): void {
    $this->withToken($this->owner['token'])->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->agent['token'])->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');
    foreach (['otherCustomer', 'otherAgent', 'admin'] as $who) {
        $this->withToken($this->{$who}['token'])->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
    }
});

describe('POST /api/broadcasting/auth (the single Task 7 route)', function (): void {
    beforeEach(fn () => ChatTesting::useReverbBroadcaster());

    $auth = function (object $test, ?string $token, string $channel, string $socket = '1234.5678'): Illuminate\Testing\TestResponse {
        $test->app['auth']->forgetGuards();
        $token === null ? $test->withoutToken() : $test->withToken($token);

        return $test->postJson('/api/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel]);
    };

    it('authorizes the owner and the assigned agent', function () use ($auth): void {
        $auth($this, $this->owner['token'], 'private-conversation.'.$this->conversation)->assertOk()->assertJsonStructure(['auth']);
        $auth($this, $this->agent['token'], 'private-conversation.'.$this->conversation, '1234.9999')->assertOk();
        $auth($this, $this->agent['token'], 'private-agents.queue', '1234.9999')->assertOk();
    });

    it('denies every unauthorized subscribe', function () use ($auth): void {
        ChatTesting::captureLogs();
        $channel = 'private-conversation.'.$this->conversation;
        $auth($this, $this->otherCustomer['token'], $channel)->assertForbidden();
        $auth($this, $this->otherAgent['token'], $channel)->assertForbidden();
        $auth($this, $this->admin['token'], $channel)->assertForbidden();
        $auth($this, $this->owner['token'], 'private-conversation.not-a-uuid')->assertForbidden();
        $auth($this, $this->owner['token'], 'private-conversation.'.Str::uuid7())->assertForbidden();
        $auth($this, $this->owner['token'], 'private-agents.queue')->assertForbidden();
        $auth($this, null, $channel)->assertUnauthorized();
        $auth($this, ChatTesting::expiredToken(), $channel)->assertUnauthorized();

        $denied = array_values(array_filter(ChatTesting::records(), fn (array $r): bool => $r['message'] === 'chat_channel_denied'));
        expect(count($denied))->toBeGreaterThanOrEqual(6)
            ->and(array_unique(array_column($denied, 'channel')))->toBe(['security'])
            ->and(ChatTesting::logText())->not->toContain($this->otherCustomer['id'])->not->toContain($this->conversation);
    });

    it('caps distinct sockets per user at channel auth (approximate, multiplier-aware)', function () use ($auth): void {
        RateLimitTesting::exact();
        config(['rate-limits.concurrency.chat-connections.max' => 2]);
        $channel = 'private-conversation.'.$this->conversation;
        $auth($this, $this->owner['token'], $channel, '1.1')->assertOk();
        $auth($this, $this->owner['token'], $channel, '1.2')->assertOk();
        $auth($this, $this->owner['token'], $channel, '1.3')->assertForbidden();
        $auth($this, $this->owner['token'], $channel, '1.1')->assertOk(); // a known socket re-authorizes
    });
});
