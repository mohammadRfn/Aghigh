<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\LabelValue;
use Modules\Shared\Metrics\Names;
use Throwable;

/**
 * Queue wait and runtime histograms from the worker events (one job at a time per worker process).
 * Wait = start of the first attempt - push time. The push time is Horizon's `pushedAt` (JobPayload::prepare(),
 * microtime, verified in horizon v5.50.1) with Laravel's `createdAt` (whole seconds) as fallback; delayed jobs and
 * retries are skipped so backoff never looks like pickup delay. This is the metric that shows the broadcast
 * pickup delay seen in Task 8.
 */
final class QueueEventMetrics
{
    private ?float $startedAt = null;

    public function __construct(private readonly Metrics $metrics) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class => 'processing',
            JobProcessed::class => 'processed',
            JobExceptionOccurred::class => 'exception',
            JobFailed::class => 'failed',
        ];
    }

    public function processing(JobProcessing $event): void
    {
        $this->startedAt = microtime(true);
        try {
            $pushedAt = self::pushedAt($event->job);
            if ($pushedAt !== null && $event->job->attempts() <= 1) {
                $this->metrics->histogram(Names::QUEUE_JOB_WAIT, max(0.0, $this->startedAt - $pushedAt), self::labels($event->job));
            }
        } catch (Throwable) {
            // never interfere with the job
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->observeRuntime($event->job);
        $this->metrics->counter(Names::QUEUE_JOBS_PROCESSED, self::labels($event->job));
    }

    public function exception(JobExceptionOccurred $event): void
    {
        $this->observeRuntime($event->job);
    }

    public function failed(JobFailed $event): void
    {
        $this->metrics->counter(Names::QUEUE_JOBS_FAILED, self::labels($event->job));
    }

    private function observeRuntime(Job $job): void
    {
        if ($this->startedAt === null) {
            return;
        }
        $runtime = max(0.0, microtime(true) - $this->startedAt);
        $this->startedAt = null;
        $this->metrics->histogram(Names::QUEUE_JOB_RUNTIME, $runtime, self::labels($job));
    }

    /** @return array{queue: string, job: string} */
    private static function labels(Job $job): array
    {
        try {
            $name = $job->resolveName();
        } catch (Throwable) {
            $name = $job->getName();
        }
        $short = (string) (strrchr($name, '\\') === false ? $name : substr(strrchr($name, '\\'), 1));

        return ['queue' => LabelValue::clean($job->getQueue()), 'job' => LabelValue::clean($short)];
    }

    private static function pushedAt(Job $job): ?float
    {
        $payload = $job->payload();
        if (isset($payload['delay']) && is_numeric($payload['delay']) && (float) $payload['delay'] > 0) {
            return null;
        }
        if (isset($payload['pushedAt']) && is_numeric($payload['pushedAt'])) {
            return (float) $payload['pushedAt'];
        }
        if (isset($payload['createdAt']) && is_numeric($payload['createdAt'])) {
            return (float) $payload['createdAt'];
        }

        return null;
    }
}
