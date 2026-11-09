<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/**
 * The only way application code records metrics (Task 9). Every metric name is declared once in
 * Modules\Shared\Metrics\Catalog (type, HELP text, label names, buckets) and referenced through the
 * constants in Modules\Shared\Metrics\Names. Feature modules never import the vendor library
 * (tests/Arch/ModulesTest.php: only Modules\Shared may use Prometheus\ or Sentry\).
 *
 * Calls never throw and never block a request or a job: the redis driver swallows every backend error,
 * counts it in a process-local counter and logs at most once per minute (Metrics\Failures).
 *
 * Cardinality rule (ADR 0010): label values must come from a bounded set (route NAME or URI template,
 * method, status class, queue, job class, limiter name, outcome, role). Never ids, emails, IPs,
 * raw URLs or request ids. Unknown metric names or label names are dropped by the redis driver and
 * rejected by the memory driver, so tests catch them.
 */
interface Metrics
{
    /** @param array<string, string> $labels */
    public function counter(string $name, array $labels = [], float|int $by = 1): void;

    /** @param array<string, string> $labels */
    public function gauge(string $name, float|int $value, array $labels = []): void;

    /** @param array<string, string> $labels */
    public function histogram(string $name, float|int $value, array $labels = []): void;
}
