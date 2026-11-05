<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Modules\Identity\Jobs\SendIdentityMail;
use Modules\Shared\Queue\Queues;

/** @return list<class-string> every queued class under Modules/<Module>/app/{Jobs,Mail,Listeners} */
function queuedClasses(): array
{
    $classes = [];
    foreach (glob(base_path('Modules/*/app/{Jobs,Mail,Listeners}/*.php'), GLOB_BRACE) ?: [] as $file) {
        $relative = substr($file, strlen(base_path('Modules/')));
        $class = 'Modules\\'.str_replace(['/app/', '/', '.php'], ['\\', '\\', ''], $relative);
        $reflection = new ReflectionClass($class);
        if (! $reflection->isAbstract() && $reflection->implementsInterface(ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('runs one auto-balanced supervisor per queue with explicit limits in every environment', function (): void {
    $defaults = config('horizon.defaults');

    expect(collect($defaults)->pluck('queue')->flatten()->sort()->values()->all())->toBe(['broadcasts', 'default', 'notifications', 'orders'])
        ->and(Queues::all())->toBe(['orders', 'broadcasts', 'notifications', 'default']);
    foreach ($defaults as $supervisor) {
        expect($supervisor['connection'])->toBe('redis')
            ->and($supervisor['balance'])->toBe('auto')
            ->and($supervisor['minProcesses'])->toBeGreaterThanOrEqual(1)
            ->and($supervisor['maxProcesses'])->toBeGreaterThanOrEqual($supervisor['minProcesses'])
            ->and($supervisor['maxJobs'])->toBeGreaterThan(0)
            ->and($supervisor['maxTime'])->toBeGreaterThan(0)
            ->and($supervisor['memory'])->toBeGreaterThan(0)
            ->and($supervisor['tries'])->toBeGreaterThan(0)
            ->and($supervisor['backoff'])->not->toBeEmpty();
    }
    foreach (['production', 'loadtest', 'local', '*'] as $environment) {
        expect(array_keys(config("horizon.environments.{$environment}")))->toBe(array_keys($defaults));
    }
});

it('keeps every job timeout and every supervisor timeout below retry_after', function (): void {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $jobs = queuedClasses();

    expect($retryAfter)->toBe(120)->and($jobs)->toContain(SendIdentityMail::class)->and(count($jobs))->toBeGreaterThanOrEqual(8);
    foreach (array_merge([config('horizon.defaults')], array_values(config('horizon.environments'))) as $supervisors) {
        foreach ($supervisors as $name => $supervisor) {
            $timeout = $supervisor['timeout'] ?? config("horizon.defaults.{$name}.timeout");
            expect($timeout)->toBeInt()->toBeLessThan($retryAfter);
        }
    }
    foreach ($jobs as $class) {
        $timeout = (new ReflectionClass($class))->getProperty('timeout')->getDefaultValue();
        expect($timeout)->toBeInt()->toBeLessThan($retryAfter);
    }
});

it('fits every database client into the PgBouncer pool and Horizon into its memory limit', function (): void {
    $compose = (string) file_get_contents(base_path('compose.yaml'));
    preg_match('/DEFAULT_POOL_SIZE: "\$\{PGBOUNCER_POOL_SIZE:-(\d+)\}"/', $compose, $pool);
    preg_match('/--workers=\$\{OCTANE_WORKERS:-(\d+)\}/', $compose, $octane);
    preg_match('/mem_limit: \$\{HORIZON_MEM_LIMIT:-(\d+)m\}/', $compose, $memory);
    $defaults = config('horizon.defaults');
    $workers = array_sum(array_column($defaults, 'maxProcesses'));

    // Octane workers + Horizon workers + Reverb + scheduler (schedule:run + one scheduled command).
    $clients = (int) $octane[1] + $workers + 1 + 2;
    // Workers, one process per supervisor and the master, each allowed `memory` MB, plus 128 MB headroom.
    $budget = ($workers + count($defaults) + 1) * max(array_column($defaults, 'memory')) + 128;

    expect($clients)->toBe(15)->toBeLessThan((int) $pool[1])
        ->and($budget)->toBeLessThanOrEqual((int) $memory[1]);
});

it('sends identity mail on the notifications queue', function (): void {
    expect((new SendIdentityMail('mail-id'))->queue)->toBe('notifications');
});

it('opens the Horizon dashboard in the local environment only', function (): void {
    $request = Request::create('/horizon');
    $original = app()->environment();

    expect(Horizon::check($request))->toBeFalse();
    app()['env'] = 'production';
    expect(Horizon::check($request))->toBeFalse();
    app()['env'] = 'local';
    expect(Horizon::check($request))->toBeTrue();
    app()['env'] = $original;
    $this->get('/horizon')->assertForbidden();
});

it('schedules expiry, outbox relay and token pruning on one server without overlap, mutexes on state', function (): void {
    $events = collect(app(Schedule::class)->events());
    $find = fn (string $needle) => $events->first(fn ($event): bool => str_contains((string) $event->description.' '.$event->command, $needle));

    foreach (['ordering:expire-reservations', 'shared:outbox-relay', 'identity:prune-tokens'] as $name) {
        $event = $find($name);
        expect($event)->not->toBeNull()
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue();
    }
    $mutex = (new ReflectionProperty(Schedule::class, 'eventMutex'))->getValue(app(Schedule::class));
    expect(config('cache.schedule_store'))->toBe('state')
        ->and($mutex->store)->toBe('state');
});
