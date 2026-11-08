<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Chat\Actions\ClaimNextConversation;
use Modules\Chat\Actions\SendMessage;
use Modules\Chat\Events\MessageSent;
use Modules\Shared\Queue\Queues;
use Tests\Support\ChatTesting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ChatTesting::configure();
    $this->customer = ChatTesting::user('customer');
    $this->agent = ChatTesting::user('support_agent');
    $this->conversation = $this->withToken($this->customer['token'])->postJson('/api/v1/conversations')->assertCreated()->json('data.id');
});

function chatPost(object $test, array $user, string $conversation, string $body, ?string $key = null): Illuminate\Testing\TestResponse
{
    return $test->withToken($user['token'])->postJson("/api/v1/conversations/{$conversation}/messages", [
        'body' => $body, 'client_message_id' => $key ?? ChatTesting::key(),
    ]);
}

it('returns the open conversation instead of creating a second one', function (): void {
    $this->withToken($this->customer['token'])->postJson('/api/v1/conversations')
        ->assertOk()->assertJsonPath('data.id', $this->conversation)->assertJsonPath('data.status', 'waiting');
    expect(DB::table('conversations')->count())->toBe(1);
});

it('replays the same client_message_id and rejects it with a different body', function (): void {
    $key = ChatTesting::key();
    $first = chatPost($this, $this->customer, $this->conversation, 'سلام', $key)->assertCreated()->assertJsonPath('data.seq', 1);
    chatPost($this, $this->customer, $this->conversation, 'سلام', $key)->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
    chatPost($this, $this->customer, $this->conversation, 'something else', $key)->assertStatus(409)
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'client-message-id-reuse'));
    expect(DB::table('messages')->count())->toBe(1)
        ->and((int) DB::table('conversations')->value('last_seq'))->toBe(1);
});

it('validates body and client_message_id', function (string $body, string $key): void {
    chatPost($this, $this->customer, $this->conversation, $body, $key)->assertStatus(422);
})->with([
    'empty' => ['', 'abcdefghijklmnop'],
    'whitespace only' => ["   \n\t ", 'abcdefghijklmnop'],
    '2001 characters' => [str_repeat('a', 2001), 'abcdefghijklmnop'],
    'key too short' => ['hi', 'short'],
    'key with bad characters' => ['hi', 'abcdefghijklmno$'],
]);

it('counts the body limit in characters: 2000 Persian characters are accepted, trimmed and stored raw', function (): void {
    $body = str_repeat('س', 2000);
    chatPost($this, $this->customer, $this->conversation, "  {$body}  ")->assertCreated();
    chatPost($this, $this->customer, $this->conversation, '<b>raw & unescaped</b>')->assertCreated();
    expect(DB::table('messages')->where('seq', 1)->value('body'))->toBe($body)
        ->and(DB::table('messages')->where('seq', 2)->value('body'))->toBe('<b>raw & unescaped</b>');
});

it('keeps a max-length Persian message within the Reverb limits (measured, not assumed)', function (): void {
    $body = str_repeat('س', (int) config('chat.body_max_chars'));
    [$message] = app(SendMessage::class)($this->customer['actor'], $this->conversation, $body, ChatTesting::key());
    $event = new MessageSent($this->conversation, $message->id);
    $payload = $event->broadcastWith() + ['socket' => null];
    $channel = 'private-conversation.'.$this->conversation;

    // What pusher-php-server 7.x POSTs to Reverb's /apps/{id}/events: data is json_encode()d (\uXXXX), then the
    // whole body is json_encode()d again (the backslash of every \uXXXX is escaped: 7 bytes per Persian character).
    $publish = json_encode(['name' => $event->broadcastAs(), 'data' => json_encode($payload, JSON_THROW_ON_ERROR), 'channels' => [$channel]], JSON_THROW_ON_ERROR);
    // What Reverb sends to each subscriber.
    $frame = json_encode(['event' => $event->broadcastAs(), 'data' => json_encode($payload, JSON_THROW_ON_ERROR), 'channel' => $channel], JSON_THROW_ON_ERROR);

    expect(mb_strlen($body))->toBe(2000)
        ->and(strlen($body))->toBe(4000)
        ->and(strlen(json_encode($body, JSON_THROW_ON_ERROR)))->toBe(12002)
        ->and(strlen($publish))->toBeGreaterThan(14000)
        ->and(strlen($publish))->toBeGreaterThan(10_000) // Reverb's default max_request_size would reject it
        ->and(strlen($publish))->toBeLessThanOrEqual((int) config('reverb.servers.reverb.max_request_size'))
        ->and(strlen($frame))->toBeLessThanOrEqual((int) config('reverb.apps.apps.0.max_message_size'));
    fwrite(STDERR, sprintf("[chat size] publish request %d bytes, subscriber frame %d bytes\n", strlen($publish), strlen($frame)));
});

it('dispatches exactly one MessageSent broadcast, after commit, on the broadcasts queue', function (): void {
    Queue::fake();
    $isMessageSent = fn (BroadcastEvent $job): bool => $job->event instanceof MessageSent;

    DB::transaction(function () use ($isMessageSent): void {
        app(SendMessage::class)($this->customer['actor'], $this->conversation, 'hello', ChatTesting::key());
        expect(Queue::pushed(BroadcastEvent::class, $isMessageSent))->toHaveCount(0); // not before commit
    });

    expect(Queue::pushed(BroadcastEvent::class, $isMessageSent))->toHaveCount(1);
    Queue::assertPushedOn(Queues::BROADCASTS, BroadcastEvent::class, $isMessageSent);
    $job = Queue::pushed(BroadcastEvent::class, $isMessageSent)->first();
    expect($job->tries)->toBe(3)->and($job->timeout)->toBeLessThan(30);
});

it('leaves no seq gap and broadcasts nothing when the send rolls back', function (): void {
    Queue::fake();
    try {
        DB::transaction(function (): void {
            app(SendMessage::class)($this->customer['actor'], $this->conversation, 'lost', ChatTesting::key());
            throw new RuntimeException('rollback after the insert');
        });
    } catch (RuntimeException) {
    }
    expect((int) DB::table('conversations')->value('last_seq'))->toBe(0)
        ->and(DB::table('messages')->count())->toBe(0)
        ->and(Queue::pushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof MessageSent))->toHaveCount(0);

    chatPost($this, $this->customer, $this->conversation, 'kept')->assertCreated()->assertJsonPath('data.seq', 1);
});

it('recovers skipped broadcasts by gap-filling with after_seq until has_more is false', function (): void {
    Event::fake([MessageSent::class]); // every broadcast is "lost"
    foreach (range(1, 5) as $i) {
        chatPost($this, $this->customer, $this->conversation, "m{$i}")->assertCreated();
    }
    $url = "/api/v1/conversations/{$this->conversation}/messages";
    $this->withToken($this->customer['token']);

    $page = $this->getJson("{$url}?after_seq=0&limit=2")->assertOk();
    expect(array_column($page->json('data'), 'seq'))->toBe([1, 2])->and($page->json('meta.has_more'))->toBeTrue()->and($page->json('meta.last_seq'))->toBe(5);
    $page = $this->getJson("{$url}?after_seq=2&limit=2")->assertOk();
    expect(array_column($page->json('data'), 'seq'))->toBe([3, 4])->and($page->json('meta.has_more'))->toBeTrue();
    $page = $this->getJson("{$url}?after_seq=4&limit=2")->assertOk();
    expect(array_column($page->json('data'), 'seq'))->toBe([5])->and($page->json('meta.has_more'))->toBeFalse();

    $newest = $this->getJson($url.'?limit=3')->assertOk();
    expect(array_column($newest->json('data'), 'seq'))->toBe([5, 4, 3])->and($newest->json('meta.has_more'))->toBeTrue();
    $older = $this->getJson($url.'?before_seq=3')->assertOk();
    expect(array_column($older->json('data'), 'seq'))->toBe([2, 1])->and($older->json('meta.has_more'))->toBeFalse();
});

it('rejects a limit above the hard maximum instead of clamping it', function (): void {
    $url = "/api/v1/conversations/{$this->conversation}/messages";
    $this->withToken($this->customer['token']);
    $this->getJson($url.'?after_seq=0&limit=201')->assertStatus(422);
    $this->getJson($url.'?limit=0')->assertStatus(422);
    $this->getJson($url.'?after_seq=0&before_seq=5')->assertStatus(422);
    $this->getJson($url.'?after_seq=0&limit=200')->assertOk()->assertJsonPath('meta.limit', 200);
    $this->getJson($url.'?after_seq=0')->assertOk()->assertJsonPath('meta.limit', 50);
});

it('applies the closed-conversation rules', function (): void {
    app(ClaimNextConversation::class)($this->agent['actor']);
    chatPost($this, $this->agent, $this->conversation, 'agent reply')->assertCreated();

    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/close")
        ->assertOk()->assertJsonPath('data.status', 'closed');

    $closed = fn (string $type): bool => str_ends_with($type, 'conversation-closed');
    chatPost($this, $this->customer, $this->conversation, 'too late')->assertStatus(409)->assertJsonPath('type', $closed);
    chatPost($this, $this->agent, $this->conversation, 'too late')->assertStatus(409)->assertJsonPath('type', $closed);
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/typing")->assertStatus(409);
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/close")->assertStatus(409);
    $this->withToken($this->agent['token'])->postJson("/api/v1/conversations/{$this->conversation}/release")->assertStatus(409);
    $this->withToken($this->customer['token'])->postJson("/api/v1/conversations/{$this->conversation}/read")->assertOk();
    $this->withToken($this->customer['token'])->getJson("/api/v1/conversations/{$this->conversation}/messages")->assertOk();

    expect(DB::table('assignments')->where('conversation_id', $this->conversation)->value('reason'))->toBe('closed');
    $this->withToken($this->customer['token'])->postJson('/api/v1/conversations')->assertCreated()
        ->assertJsonPath('data.id', fn (string $id): bool => $id !== $this->conversation);
});

it('lets an agent post only in a conversation claimed by them', function (): void {
    chatPost($this, $this->agent, $this->conversation, 'not mine yet')->assertNotFound();
    app(ClaimNextConversation::class)($this->agent['actor']);
    chatPost($this, $this->agent, $this->conversation, 'mine now')->assertCreated()->assertJsonPath('data.sender_role', 'support_agent');
    chatPost($this, $this->customer, $this->conversation, 'customer while claimed')->assertCreated()->assertJsonPath('data.seq', 2);
});
