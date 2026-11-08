<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Chat\Actions\ClaimNextConversation;
use Modules\Chat\Events\TypingStarted;
use Modules\Chat\State\ChatState;
use Modules\Chat\State\InMemoryChatState;
use Tests\Support\ChatTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ChatTesting::configure();
    $this->customer = ChatTesting::user('customer');
    $this->agent = ChatTesting::user('support_agent');
    $this->admin = ChatTesting::user('admin');
    $this->conversation = $this->withToken($this->customer['token'])->postJson('/api/v1/conversations')->assertCreated()->json('data.id');
});

function unreadOf(object $test, array $user): int
{
    return (int) $test->withToken($user['token'])->getJson('/api/v1/conversations')->assertOk()->json('data.0.unread');
}

function flushChatState(): void
{
    $state = app(ChatState::class);
    expect($state)->toBeInstanceOf(InMemoryChatState::class);
    $state->flush();
}

it('never changes the unread numbers clients see when Redis is flushed', function (): void {
    $this->withToken($this->agent['token'])->postJson('/api/v1/support/queue/claim')->assertOk()->assertJsonPath('data.id', $this->conversation);
    foreach (['a', 'b', 'c'] as $body) {
        $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/messages", ['body' => $body, 'client_message_id' => ChatTesting::key()])->assertCreated();
    }
    expect(unreadOf($this, $this->agent))->toBe(3)->and(unreadOf($this, $this->customer))->toBe(0);
    flushChatState();
    expect(unreadOf($this, $this->agent))->toBe(3);

    $this->withToken($this->agent['token'])->postJson("/api/v1/conversations/{$this->conversation}/read", ['last_read_seq' => 2])
        ->assertOk()->assertJsonPath('data.unread', 1);
    expect(unreadOf($this, $this->agent))->toBe(1);
    flushChatState();
    expect(unreadOf($this, $this->agent))->toBe(1);

    $this->withToken($this->agent['token'])->postJson("/api/v1/conversations/{$this->conversation}/messages", ['body' => 'reply', 'client_message_id' => ChatTesting::key()])->assertCreated();
    expect(unreadOf($this, $this->customer))->toBe(1)->and(unreadOf($this, $this->agent))->toBe(0);
    flushChatState();
    $this->artisan('chat:rebuild-unread')->assertExitCode(0);
    expect(unreadOf($this, $this->customer))->toBe(1)->and(unreadOf($this, $this->agent))->toBe(0);

    // the read marker never moves backwards and never past last_seq
    $this->withToken($this->agent['token'])->postJson("/api/v1/conversations/{$this->conversation}/read", ['last_read_seq' => 1])->assertJsonPath('data.last_read_seq', 4);
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/read", ['last_read_seq' => 999])->assertJsonPath('data.last_read_seq', 4);
});

it('shows online agents to customers as a boolean and the list to agents and admins', function (): void {
    $this->withToken($this->customer['token'])->getJson('/api/v1/presence')->assertOk()->assertJsonPath('data.agents_online', false);
    $this->withToken($this->agent['token'])->postJson('/api/v1/presence/heartbeat')->assertOk();
    $this->withToken($this->customer['token'])->getJson('/api/v1/presence')->assertOk()
        ->assertJsonPath('data.agents_online', true)->assertJsonMissingPath('data.online_user_ids');
    expect($this->withToken($this->admin['token'])->getJson('/api/v1/presence')->assertOk()->json('data.online_user_ids'))->toContain($this->agent['id']);
    $this->withToken($this->agent['token'])->postJson('/api/v1/presence/heartbeat', ['socket_id' => 'bad'])->assertStatus(422);
});

it('throttles typing broadcasts to one per window, with tries 1, and never persists them', function (): void {
    Queue::fake();
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/typing")->assertStatus(202)->assertJsonPath('data.broadcast', true);
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/typing")->assertStatus(202)->assertJsonPath('data.broadcast', false);
    $typing = Queue::pushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof TypingStarted);
    expect($typing)->toHaveCount(1)->and($typing->first()->tries)->toBe(1)->and(DB::table('messages')->count())->toBe(0);
});

it('claims, releases with history, and lets another agent claim; an empty queue answers 204', function (): void {
    $other = ChatTesting::user('support_agent');
    $this->withToken($this->agent['token'])->postJson('/api/v1/support/queue/claim')->assertOk()->assertJsonPath('data.assigned_agent_id', $this->agent['id']);
    $this->withToken($other['token'])->postJson('/api/v1/support/queue/claim')->assertNoContent();
    $this->withToken($this->agent['token'])->postJson("/api/v1/conversations/{$this->conversation}/release")->assertOk()->assertJsonPath('data.status', 'waiting');
    expect(app(ChatState::class)->queueScore($this->conversation))->not->toBeNull();
    $this->withToken($other['token'])->postJson('/api/v1/support/queue/claim')->assertOk()->assertJsonPath('data.id', $this->conversation);

    $history = DB::table('assignments')->where('conversation_id', $this->conversation)->orderBy('assigned_at')->get();
    expect($history)->toHaveCount(2)
        ->and($history[0]->reason)->toBe('released')->and($history[0]->released_at)->not->toBeNull()
        ->and($history[1]->reason)->toBe('claimed')->and($history[1]->released_at)->toBeNull();
});

it('honours the optional per-agent cap of open conversations', function (): void {
    config(['chat.agent_max_open' => 1]);
    $second = ChatTesting::user('customer');
    $this->withToken($second['token'])->postJson('/api/v1/conversations')->assertCreated();
    app(ClaimNextConversation::class)($this->agent['actor']);
    $this->withToken($this->agent['token'])->postJson('/api/v1/support/queue/claim')->assertStatus(409);
});

it('verifies invariants and fails on a corrupted counter', function (): void {
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/messages", ['body' => 'x', 'client_message_id' => ChatTesting::key()])->assertCreated();
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
    DB::table('conversations')->where('id', $this->conversation)->update(['last_seq' => 7]);
    $this->artisan('chat:verify-invariants')->assertExitCode(1);
});

it('rebuilds the queue after the state is lost', function (): void {
    $score = app(ChatState::class)->queueScore($this->conversation);
    flushChatState();
    $this->artisan('chat:verify-invariants')->assertExitCode(1);
    $this->artisan('chat:rebuild-queue')->assertExitCode(0);
    expect(app(ChatState::class)->queueScore($this->conversation))->toBe($score);
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
});
