<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Chat\Actions\ClaimNextConversation;
use Modules\Chat\State\ChatState;
use Modules\Chat\State\RedisChatState;
use Tests\Support\ChatTesting;
use Tests\Support\IdentityTesting;
use Tests\Support\Race;

// Real Postgres (aghigh_test) + real Redis (state db from make test-int), real parallel processes.
const CHAT_RACE_ENV = ['QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CHAT_STATE_DRIVER' => 'redis'];

beforeEach(function (): void {
    IdentityTesting::configure(false); // real state Redis; exports JWT keys to the workers
    config(['queue.default' => 'sync', 'broadcasting.default' => 'null', 'chat.state_driver' => 'redis']);
    app()->forgetInstance(ChatState::class);
    Redis::connection('state')->flushdb();
    expect(app(ChatState::class))->toBeInstanceOf(RedisChatState::class);
});

function chatWorker(): string
{
    return __DIR__.'/chat-worker.php';
}

function openFor(object $test, array $customer): string
{
    return (string) $test->withToken($customer['token'])->postJson('/api/v1/conversations')->assertSuccessful()->json('data.id');
}

/** @param list<array<string, mixed>> $results */
function statusCount(array $results, int $status): int
{
    return count(array_filter($results, fn (array $r): bool => $r['status'] === $status));
}

it('stores one row for a duplicated client_message_id, sequentially and with 20 parallel senders', function (): void {
    $customer = ChatTesting::user('customer');
    $conversation = openFor($this, $customer);
    $url = "/api/v1/conversations/{$conversation}/messages";

    $sequential = json_encode(['body' => 'once', 'client_message_id' => ChatTesting::key()], JSON_THROW_ON_ERROR);
    $first = Race::run(chatWorker(), [['POST', $url, $customer['token'], $sequential]], environment: CHAT_RACE_ENV);
    $second = Race::run(chatWorker(), [['POST', $url, $customer['token'], $sequential]], environment: CHAT_RACE_ENV);
    expect($first[0]['status'])->toBe(201)->and($second[0]['status'])->toBe(200)->and($second[0]['id'])->toBe($first[0]['id']);

    $body = json_encode(['body' => 'parallel', 'client_message_id' => ChatTesting::key()], JSON_THROW_ON_ERROR);
    $results = Race::run(chatWorker(), array_fill(0, 20, ['POST', $url, $customer['token'], $body]), environment: CHAT_RACE_ENV);

    expect(statusCount($results, 201))->toBe(1)
        ->and(statusCount($results, 200))->toBe(19)
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(DB::table('messages')->where('conversation_id', $conversation)->count())->toBe(2)
        ->and((int) DB::table('conversations')->where('id', $conversation)->value('last_seq'))->toBe(2); // no gap
    $this->artisan('chat:verify-invariants', ['--skip-queue' => true])->assertExitCode(0);
});

it('allocates contiguous seq without duplicates under 30 parallel senders', function (): void {
    $customer = ChatTesting::user('customer');
    $conversation = openFor($this, $customer);
    $workers = [];
    for ($i = 0; $i < 30; $i++) {
        $workers[] = ['POST', "/api/v1/conversations/{$conversation}/messages", $customer['token'], json_encode(['body' => "m{$i}", 'client_message_id' => ChatTesting::key()], JSON_THROW_ON_ERROR)];
    }
    $results = Race::run(chatWorker(), $workers, environment: CHAT_RACE_ENV);
    $seqs = array_column($results, 'seq');
    sort($seqs);

    expect(statusCount($results, 201))->toBe(30)
        ->and($seqs)->toBe(range(1, 30))
        ->and(DB::table('messages')->where('conversation_id', $conversation)->orderBy('seq')->pluck('seq')->map(fn ($s): int => (int) $s)->all())->toBe(range(1, 30))
        ->and((int) DB::table('conversations')->where('id', $conversation)->value('last_seq'))->toBe(30);
    $this->artisan('chat:verify-invariants', ['--skip-queue' => true])->assertExitCode(0);
});

it('creates exactly one open conversation for 20 parallel POST /conversations by one customer', function (): void {
    $customer = ChatTesting::user('customer');
    $results = Race::run(chatWorker(), array_fill(0, 20, ['POST', '/api/v1/conversations', $customer['token'], '']), environment: CHAT_RACE_ENV);

    expect(statusCount($results, 201))->toBe(1)
        ->and(statusCount($results, 200))->toBe(19)
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(DB::table('conversations')->where('customer_id', $customer['id'])->where('status', '<>', 'closed')->count())->toBe(1);
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
});

it('hands 5 queued conversations to exactly 5 of 20 racing agents, none twice', function (): void {
    $conversations = [];
    for ($i = 0; $i < 5; $i++) {
        $conversations[] = openFor($this, ChatTesting::user('customer'));
    }
    $agents = [];
    for ($i = 0; $i < 20; $i++) {
        $agents[] = ['POST', '/api/v1/support/queue/claim', ChatTesting::user('support_agent')['token'], ''];
    }
    $results = Race::run(chatWorker(), $agents, environment: CHAT_RACE_ENV);
    $claimed = array_values(array_filter(array_column($results, 'id')));

    expect(statusCount($results, 200))->toBe(5)
        ->and(statusCount($results, 204))->toBe(15)
        ->and(array_unique($claimed))->toHaveCount(5)
        ->and(array_diff($conversations, $claimed))->toBe([])
        ->and(DB::table('conversations')->where('status', 'claimed')->count())->toBe(5)
        ->and(DB::table('assignments')->whereNull('released_at')->count())->toBe(5)
        ->and(app(ChatState::class)->queueSize())->toBe(0);
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
});

it('re-adds the entry with its original score when the claim UPDATE fails in the database', function (): void {
    $conversation = openFor($this, ChatTesting::user('customer'));
    $score = app(ChatState::class)->queueScore($conversation);
    expect($score)->not->toBeNull();

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION chat_test_fail_claim() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'injected claim failure'; END $$;
        CREATE TRIGGER chat_test_fail_claim BEFORE UPDATE ON conversations
        FOR EACH ROW WHEN (NEW.status = 'claimed') EXECUTE FUNCTION chat_test_fail_claim();
        SQL);
    $agent = ChatTesting::user('support_agent');

    expect(fn () => app(ClaimNextConversation::class)($agent['actor']))->toThrow(QueryException::class);
    expect(app(ChatState::class)->queueScore($conversation))->toBe($score)
        ->and(DB::table('conversations')->where('id', $conversation)->value('status'))->toBe('waiting');

    DB::unprepared('DROP TRIGGER chat_test_fail_claim ON conversations');
    expect(app(ClaimNextConversation::class)($agent['actor'])?->id)->toBe($conversation);
});

it('admits at most the cap of distinct sockets per user under parallel channel auths', function (): void {
    $customer = ChatTesting::user('customer');
    $conversation = openFor($this, $customer);
    $workers = [];
    for ($i = 0; $i < 12; $i++) {
        $workers[] = ['POST', '/api/broadcasting/auth', $customer['token'], json_encode(['socket_id' => "9{$i}.{$i}", 'channel_name' => 'private-conversation.'.$conversation], JSON_THROW_ON_ERROR)];
    }
    $results = Race::run(chatWorker(), $workers, environment: array_merge(CHAT_RACE_ENV, [
        'BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_KEY' => 'race-key', 'REVERB_APP_SECRET' => 'race-secret', 'REVERB_APP_ID' => '100001',
        'RATE_LIMIT_MULTIPLIER' => '1', 'CHAT_CONNECTIONS_PER_USER' => '3', 'BROADCASTING_AUTH_RATE' => '1000',
    ]));

    expect(statusCount($results, 200))->toBe(3)
        ->and(statusCount($results, 403))->toBe(9)
        ->and(app(ChatState::class)->connectionCount($customer['id'], 120000, (int) floor(microtime(true) * 1000)))->toBe(3);
});

it('rebuilds the support queue after the state Redis is flushed', function (): void {
    $scores = [];
    for ($i = 0; $i < 4; $i++) {
        $id = openFor($this, ChatTesting::user('customer'));
        $scores[$id] = app(ChatState::class)->queueScore($id);
    }
    $oldest = (string) array_key_first($scores);
    expect(app(ClaimNextConversation::class)(ChatTesting::user('support_agent')['actor'])?->id)->toBe($oldest);
    unset($scores[$oldest]);

    Redis::connection('state')->flushdb();
    $this->artisan('chat:verify-invariants')->assertExitCode(1);
    $this->artisan('chat:rebuild-queue')->assertExitCode(0);

    expect(app(ChatState::class)->queueSize())->toBe(3)->and(app(ChatState::class)->queueScore($oldest))->toBeNull();
    foreach ($scores as $id => $score) {
        expect(app(ChatState::class)->queueScore((string) $id))->toBe($score); // original order preserved (score = created_at)
    }
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
});

it('repairs a crash between ZPOPMIN and the claim UPDATE', function (): void {
    $conversation = openFor($this, ChatTesting::user('customer'));
    $score = app(ChatState::class)->queueScore($conversation);

    // the "crashed" claim: the entry left Redis, the database still says waiting
    expect(app(ChatState::class)->queuePopMin())->toBe([$conversation, $score]);
    $this->artisan('chat:verify-invariants')->assertExitCode(1);

    $this->artisan('chat:rebuild-queue')->assertExitCode(0);
    expect(app(ChatState::class)->queueScore($conversation))->toBe($score);
    $this->artisan('chat:verify-invariants')->assertExitCode(0);
    expect(app(ClaimNextConversation::class)(ChatTesting::user('support_agent')['actor'])?->id)->toBe($conversation);
});
