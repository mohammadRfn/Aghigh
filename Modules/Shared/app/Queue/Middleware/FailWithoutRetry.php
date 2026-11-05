<?php

declare(strict_types=1);

namespace Modules\Shared\Queue\Middleware;

use Throwable;

/**
 * Definitive (business) failures: fail the job at once, no retry, no rethrow. Put it OUTERMOST so the
 * inner WithoutOverlapping lock is already released when failed() and chain catch callbacks run.
 * Laravel's FailOnException rethrows, which the sync driver turns into an HTTP 500 and the worker
 * logs as an error; a declined payment is neither.
 */
final class FailWithoutRetry
{
    /** @param list<class-string<Throwable>> $exceptions */
    public function __construct(private readonly array $exceptions) {}

    public function handle(object $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $error) {
            foreach ($this->exceptions as $class) {
                if ($error instanceof $class && method_exists($job, 'fail')) {
                    $job->fail($error);

                    return null;
                }
            }
            throw $error;
        }
    }
}
