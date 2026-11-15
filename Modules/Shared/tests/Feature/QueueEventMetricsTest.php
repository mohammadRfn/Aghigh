<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Modules\Shared\Metrics\Listeners\QueueEventMetrics;
use Tests\Support\MetricsTesting;

const QUEUE_SERIES = '{queue="orders",job="ChargePayment"}';

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
});

function queueListener(): QueueEventMetrics
{
    return app(QueueEventMetrics::class);
}

it('is subscribed to the four worker events', function (): void {
    $raw = Event::getRawListeners();

    foreach ([JobProcessing::class => 'processing', JobProcessed::class => 'processed', JobExceptionOccurred::class => 'exception', JobFailed::class => 'failed'] as $event => $method) {
        expect($raw[$event] ?? [])->toContain([QueueEventMetrics::class, $method]);
    }
});

it('observes the pickup delay from Horizon pushedAt on the first attempt', function (): void {
    queueListener()->processing(new JobProcessing('redis', MetricsTesting::job(['pushedAt' => microtime(true) - 2.5])));

    $sum = MetricsTesting::sample('aghigh_queue_job_wait_seconds_sum'.QUEUE_SERIES);
    expect(MetricsTesting::sample('aghigh_queue_job_wait_seconds_count'.QUEUE_SERIES))->toBe(1.0)
        ->and($sum)->toBeGreaterThanOrEqual(2.5)->and($sum)->toBeLessThan(8.0);
});

it('falls back to createdAt when there is no pushedAt', function (): void {
    queueListener()->processing(new JobProcessing('redis', MetricsTesting::job(['createdAt' => time() - 4])));

    expect(MetricsTesting::sample('aghigh_queue_job_wait_seconds_count'.QUEUE_SERIES))->toBe(1.0)
        ->and(MetricsTesting::sample('aghigh_queue_job_wait_seconds_sum'.QUEUE_SERIES))->toBeGreaterThanOrEqual(3.0);
});

it('does not count retries or delayed jobs as pickup delay', function (): void {
    queueListener()->processing(new JobProcessing('redis', MetricsTesting::job(['pushedAt' => microtime(true) - 30], attempts: 2)));
    queueListener()->processing(new JobProcessing('redis', MetricsTesting::job(['pushedAt' => microtime(true) - 30, 'delay' => 60])));
    queueListener()->processing(new JobProcessing('redis', MetricsTesting::job([])));

    expect(MetricsTesting::has('aghigh_queue_job_wait_seconds_count'))->toBeFalse();
});

it('counts a processed job and observes its runtime, labelled by queue and short class name', function (): void {
    $job = MetricsTesting::job(['pushedAt' => microtime(true)]);
    queueListener()->processing(new JobProcessing('redis', $job));
    queueListener()->processed(new JobProcessed('redis', $job));

    expect(MetricsTesting::sample('aghigh_queue_jobs_processed_total'.QUEUE_SERIES))->toBe(1.0)
        ->and(MetricsTesting::sample('aghigh_queue_job_runtime_seconds_count'.QUEUE_SERIES))->toBe(1.0)
        ->and(MetricsTesting::body())->not->toContain('Modules\\Ordering');
});

it('records runtime but not "processed" for an attempt that threw, and counts only final failures', function (): void {
    $job = MetricsTesting::job(['pushedAt' => microtime(true)]);
    queueListener()->processing(new JobProcessing('redis', $job));
    queueListener()->exception(new JobExceptionOccurred('redis', $job, new RuntimeException('boom')));

    expect(MetricsTesting::sample('aghigh_queue_job_runtime_seconds_count'.QUEUE_SERIES))->toBe(1.0)
        ->and(MetricsTesting::has('aghigh_queue_jobs_processed_total'))->toBeFalse()
        ->and(MetricsTesting::has('aghigh_queue_jobs_failed_total'))->toBeFalse();

    queueListener()->failed(new JobFailed('redis', $job, new RuntimeException('boom')));

    expect(MetricsTesting::sample('aghigh_queue_jobs_failed_total'.QUEUE_SERIES))->toBe(1.0);
});

it('does not observe a runtime without a start, and never twice for one start', function (): void {
    $job = MetricsTesting::job([]);
    queueListener()->processed(new JobProcessed('redis', $job));
    expect(MetricsTesting::has('aghigh_queue_job_runtime_seconds_count'))->toBeFalse();

    queueListener()->processing(new JobProcessing('redis', $job));
    queueListener()->exception(new JobExceptionOccurred('redis', $job, new RuntimeException('x')));
    queueListener()->processed(new JobProcessed('redis', $job));

    expect(MetricsTesting::sample('aghigh_queue_job_runtime_seconds_count'.QUEUE_SERIES))->toBe(1.0);
});

it('never throws into the worker when the payload cannot be read', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andThrow(new RuntimeException('bad payload'));
    $job->shouldReceive('attempts')->andReturn(1);

    expect(fn () => queueListener()->processing(new JobProcessing('redis', $job)))->not->toThrow(Throwable::class);
});

it('falls back to getName() when resolveName() throws', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getQueue')->andReturn('orders');
    $job->shouldReceive('resolveName')->andThrow(new RuntimeException('cannot resolve'));
    $job->shouldReceive('getName')->andReturn('Modules\\Ordering\\Jobs\\ConfirmOrder');

    queueListener()->failed(new JobFailed('redis', $job, new RuntimeException('x')));

    expect(MetricsTesting::sample('aghigh_queue_jobs_failed_total{queue="orders",job="ConfirmOrder"}'))->toBe(1.0);
});
