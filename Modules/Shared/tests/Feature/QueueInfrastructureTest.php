<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Shared\Contracts\OutboxDomainEvent;
use Modules\Shared\Events\OutboxMessage;
use Modules\Shared\Outbox\Outbox;
use Modules\Shared\Outbox\OutboxEventMap;
use Modules\Shared\Outbox\OutboxRelay;
use Modules\Shared\Queue\Backoff;
use Modules\Shared\Queue\Middleware\ThrottlesExceptionsWithBackoff;
use Modules\Shared\Queue\Middleware\WithoutOverlappingOnState;
use Modules\Shared\Queue\QueueMetrics;

uses(RefreshDatabase::class);

final class SharedQueueProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<mixed> */
    public static array $seen = [];

    /** @return list<int> */
    public function backoff(): array
    {
        return [7, 21];
    }

    public function handle(): void
    {
        self::$seen[] = Context::get('request_id');
    }
}

final readonly class SharedProbeEvent implements OutboxDomainEvent
{
    public function __construct(public string $eventId) {}

    public static function fromOutbox(OutboxMessage $message): static
    {
        return new self($message->id);
    }
}

beforeEach(function (): void {
    config(['cache.stores.state' => ['driver' => 'array', 'serialize' => false], 'queue.locks.store' => 'state']);
    Cache::forgetDriver('state');
    SharedQueueProbeJob::$seen = [];
});

it('propagates the request id into queued jobs through the payload', function (): void {
    Context::add('request_id', 'req-propagation-123');
    dispatch((new SharedQueueProbeJob)->onConnection('database')->onQueue('default'));
    $payload = (string) DB::table('jobs')->value('payload');
    Context::flush();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);

    expect($payload)->toContain('illuminate:log:context')->toContain('req-propagation-123')
        ->and(SharedQueueProbeJob::$seen)->toBe(['req-propagation-123'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('locks WithoutOverlappingOnState on the configured store, not on the default cache store', function (): void {
    $middleware = (new WithoutOverlappingOnState('probe'))->shared()->releaseAfter(3);
    $ran = 0;
    $job = (new SharedQueueProbeJob)->withFakeQueueInteractions();

    Cache::store('array')->lock('laravel-queue-overlap:probe', 30)->get(); // default store: irrelevant
    $middleware->handle($job, function () use (&$ran): void {
        $ran++;
    });
    Cache::store('state')->lock('laravel-queue-overlap:probe', 30)->get(); // the configured store blocks
    $middleware->handle($job, function () use (&$ran): void {
        $ran++;
    });

    expect($ran)->toBe(1);
    $job->assertReleased(3);
});

it('builds exponential backoff and throttles only matching exceptions with it', function (): void {
    expect(Backoff::exponential(5, 5, 120))->toBe([5, 15, 45, 120, 120])
        ->and(Backoff::exponential(2, 4, 300, 2))->toBe([2, 4, 8, 16])
        ->and(Backoff::delayFor(new SharedQueueProbeJob))->toBe(7);

    $job = (new SharedQueueProbeJob)->withFakeQueueInteractions();
    $throttle = (new ThrottlesExceptionsWithBackoff(5, 60))->by('probe-gateway')->when(fn (Throwable $e): bool => $e instanceof DomainException);
    $throttle->handle($job, fn () => throw new DomainException('gateway down'));
    $job->assertReleased(7);

    expect(fn () => $throttle->handle((new SharedQueueProbeJob)->withFakeQueueInteractions(), fn () => throw new LogicException('bug')))
        ->toThrow(LogicException::class);
});

it('relays due outbox rows once and publishes unknown types as OutboxMessage', function (): void {
    app(OutboxEventMap::class)->register(['ProbeHappened' => SharedProbeEvent::class]);
    Event::fake([SharedProbeEvent::class, OutboxMessage::class]);
    DB::transaction(function (): void {
        app(Outbox::class)->record('probe', 'agg-1', 'ProbeHappened', ['id' => 'agg-1']);
        app(Outbox::class)->record('probe', 'agg-2', 'SomethingElse', []);
    });

    expect(app(OutboxRelay::class)->relayBatch(10))->toBe([2, 2])
        ->and(app(OutboxRelay::class)->relayBatch(10))->toBe([0, 0])
        ->and(DB::table('outbox')->whereNull('processed_at')->count())->toBe(0);
    Event::assertDispatched(SharedProbeEvent::class, 1);
    Event::assertDispatched(OutboxMessage::class, fn (OutboxMessage $message): bool => $message->type === 'SomethingElse');
});

it('keeps a failing row due later with its attempt counted and no message persisted', function (): void {
    app(OutboxEventMap::class)->register(['ProbeHappened' => SharedProbeEvent::class]);
    Event::listen(SharedProbeEvent::class, fn () => throw new RuntimeException('secret detail must not be stored'));
    DB::transaction(fn (): string => app(Outbox::class)->record('probe', 'agg-3', 'ProbeHappened', []));

    expect(app(OutboxRelay::class)->relayBatch(10))->toBe([1, 0]);
    $row = DB::table('outbox')->first();
    expect($row->processed_at)->toBeNull()
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->last_error)->toBe(RuntimeException::class)
        ->and(CarbonImmutable::parse((string) $row->available_at)->isFuture())->toBeTrue()
        ->and(app(OutboxRelay::class)->relayBatch(10))->toBe([0, 0]);
});

it('reports depth, oldest age and failures, and drain-time waits for an empty backlog', function (): void {
    dispatch((new SharedQueueProbeJob)->onConnection('database')->onQueue('orders'));
    dispatch((new SharedQueueProbeJob)->onConnection('database')->onQueue('orders')->delay(now()->addMinutes(5)));
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'orders', 'payload' => '{}',
        'exception' => 'RuntimeException', 'failed_at' => now(),
    ]);

    $snapshot = app(QueueMetrics::class)->snapshot(null, 'database');
    expect(array_keys($snapshot['queues']))->toBe(['orders', 'broadcasts', 'notifications', 'default'])
        ->and($snapshot['queues']['orders'])->toMatchArray(['pending' => 1, 'delayed' => 1, 'reserved' => 0, 'total' => 2, 'estimated_wait_seconds' => null])
        ->and($snapshot['queues']['orders']['oldest_pending_age_seconds'])->toBeGreaterThanOrEqual(0)
        ->and($snapshot['queues']['notifications']['total'])->toBe(0)
        ->and($snapshot['failed'])->toBe(['stored' => 1, 'horizon_recent' => null]);

    Artisan::call('queue:metrics', ['--json' => true, '--connection' => 'database']);
    expect(json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR)['queues']['orders']['pending'])->toBe(1)
        ->and(Artisan::call('queue:drain-time', ['--connection' => 'database', '--timeout' => 0.3, '--interval' => 0.1]))->toBe(1);

    DB::table('jobs')->delete();
    expect(Artisan::call('queue:drain-time', ['--connection' => 'database', '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR))->toMatchArray(['drained' => true, 'backlog' => 0]);
});
