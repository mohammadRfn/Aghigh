<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChatTesting;
use Tests\Support\RateLimitTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ChatTesting::configure();
    $this->customer = ChatTesting::user('customer');
    $this->conversation = $this->withToken($this->customer['token'])->postJson('/api/v1/conversations')->assertCreated()->json('data.id');
});

it('limits messages per user with the chat-messages limiter (after auth)', function (): void {
    RateLimitTesting::exact(['chat-messages' => 2]);
    $url = "/api/v1/conversations/{$this->conversation}/messages";
    $this->withToken($this->customer['token']);
    $this->postJson($url, ['body' => '1', 'client_message_id' => ChatTesting::key()])->assertCreated()->assertHeader('RateLimit-Limit');
    $this->postJson($url, ['body' => '2', 'client_message_id' => ChatTesting::key()])->assertCreated();
    $this->postJson($url, ['body' => '3', 'client_message_id' => ChatTesting::key()])->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'too-many-requests'));
});

it('rejects an oversized body by character count', function (): void {
    config(['chat.body_max_chars' => 10]);
    $url = "/api/v1/conversations/{$this->conversation}/messages";
    $this->withToken($this->customer['token'])->postJson($url, ['body' => str_repeat('ب', 10), 'client_message_id' => ChatTesting::key()])->assertCreated();
    $this->withToken($this->customer['token'])->postJson($url, ['body' => str_repeat('ب', 11), 'client_message_id' => ChatTesting::key()])->assertStatus(422);
});

it('never writes message bodies to any log channel', function (): void {
    ChatTesting::captureLogs();
    $agent = ChatTesting::user('support_agent');
    $marker = 'BODY-MARKER-'.bin2hex(random_bytes(6)).'-پیام-محرمانه';
    $url = "/api/v1/conversations/{$this->conversation}/messages";

    $this->withToken($agent['token'])->postJson('/api/v1/support/queue/claim')->assertOk();
    $this->withToken($this->customer['token'])->postJson($url, ['body' => $marker, 'client_message_id' => ChatTesting::key()])->assertCreated();
    $this->withToken($this->customer['token'])->postJson($url, ['body' => $marker.str_repeat('x', 3000), 'client_message_id' => ChatTesting::key()])->assertStatus(422);
    $this->withToken($agent['token'])->postJson($url, ['body' => $marker, 'client_message_id' => ChatTesting::key()])->assertCreated();
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/close")->assertOk();
    $this->withToken($this->customer['token'])->postJson($url, ['body' => $marker, 'client_message_id' => ChatTesting::key()])->assertStatus(409);

    $messages = array_column(ChatTesting::records(), 'message');
    expect($messages)->toContain('chat_conversation_claimed')->toContain('chat_conversation_closed')
        ->and(ChatTesting::logText())->not->toContain('BODY-MARKER')->not->toContain('پیام-محرمانه');
});
