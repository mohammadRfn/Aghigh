<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

use Closure;
use InvalidArgumentException;
use Modules\Shared\Contracts\Metrics;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Throwable;

/**
 * Metrics on top of promphp/prometheus_client_php with any storage adapter: Redis (production, shared by every
 * Octane worker and Horizon process, nothing kept in PHP memory) or InMemory (tests). The registry is built lazily
 * and dropped after a failure so the next write re-resolves the storage (and the Laravel Redis connection).
 *
 * Nothing here throws, except programming errors (unknown metric or label) when $strict is on (memory driver).
 */
final class PrometheusMetrics implements Metrics, MetricsStore
{
    private ?CollectorRegistry $registry = null;

    /**
     * @param  Closure(): Adapter  $storage  builds (or re-resolves) the storage adapter
     * @param  Closure(): void|null  $reset  called after a failure, e.g. to purge the Laravel Redis connection
     */
    public function __construct(
        private readonly Closure $storage,
        private readonly string $driver,
        private readonly bool $strict = false,
        private readonly ?Closure $reset = null,
    ) {}

    public function counter(string $name, array $labels = [], float|int $by = 1): void
    {
        $this->guard(function (CollectorRegistry $registry) use ($name, $labels, $by): void {
            $definition = $this->definition($name, 'counter');
            if ($by <= 0) {
                return;
            }
            $registry->getOrRegisterCounter('', $definition->name, $definition->help, $definition->labelNames)
                ->incBy($by, $this->values($definition, $labels));
        });
    }

    public function gauge(string $name, float|int $value, array $labels = []): void
    {
        $this->guard(function (CollectorRegistry $registry) use ($name, $labels, $value): void {
            $definition = $this->definition($name, 'gauge');
            $registry->getOrRegisterGauge('', $definition->name, $definition->help, $definition->labelNames)
                ->set((float) $value, $this->values($definition, $labels));
        });
    }

    public function histogram(string $name, float|int $value, array $labels = []): void
    {
        $this->guard(function (CollectorRegistry $registry) use ($name, $labels, $value): void {
            $definition = $this->definition($name, 'histogram');
            $registry->getOrRegisterHistogram('', $definition->name, $definition->help, $definition->labelNames, $definition->buckets)
                ->observe((float) $value, $this->values($definition, $labels));
        });
    }

    public function render(): ?string
    {
        try {
            $text = (new RenderTextFormat)->render($this->registry()->getMetricFamilySamples());
        } catch (Throwable $error) {
            $this->failed($error);

            return null;
        }

        return $text;
    }

    public function wipe(): void
    {
        $this->registry()->wipeStorage();
        $this->registry = null;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private function registry(): CollectorRegistry
    {
        return $this->registry ??= new CollectorRegistry(($this->storage)(), false);
    }

    /** @param Closure(CollectorRegistry): void $operation */
    private function guard(Closure $operation): void
    {
        try {
            $registry = $this->registry();
            $operation($registry);
            $this->flushFailures($registry);
        } catch (InvalidArgumentException $error) {
            if ($this->strict) {
                throw $error;
            }
            $this->failed($error);
        } catch (Throwable $error) {
            $this->failed($error);
        }
    }

    private function failed(Throwable $error): void
    {
        $this->registry = null;
        Failures::record($this->driver, $error);
        try {
            if ($this->reset !== null) {
                ($this->reset)();
            }
        } catch (Throwable) {
            // nothing left to do
        }
    }

    /** After a successful write, move the process-local failure count into the shared counter (exactly once). */
    private function flushFailures(CollectorRegistry $registry): void
    {
        if (Failures::pending() === 0) {
            return;
        }
        $count = Failures::take();
        try {
            $definition = Catalog::get(Names::METRICS_WRITE_FAILURES);
            if ($definition !== null) {
                $registry->getOrRegisterCounter('', $definition->name, $definition->help, $definition->labelNames)
                    ->incBy($count, [$this->driver]);
            }
            $errors = Catalog::get(Names::STATE_STORE_ERRORS);
            if ($errors !== null) {
                $registry->getOrRegisterCounter('', $errors->name, $errors->help, $errors->labelNames)
                    ->incBy($count, ['metrics']);
            }
        } catch (Throwable $error) {
            Failures::restore($count);
            throw $error;
        }
    }

    private function definition(string $name, string $type): MetricDefinition
    {
        $definition = Catalog::get($name);
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown metric {$name}: declare it in Modules\\Shared\\Metrics\\Catalog.");
        }
        if ($definition->type !== $type) {
            throw new InvalidArgumentException("Metric {$name} is a {$definition->type}, not a {$type}.");
        }

        return $definition;
    }

    /**
     * Label values in the declared order; unknown label names are a programming error, missing ones become "none".
     *
     * @param  array<string, string|int|float|bool|null>  $labels
     * @return list<string>
     */
    private function values(MetricDefinition $definition, array $labels): array
    {
        $unknown = array_diff(array_keys($labels), $definition->labelNames);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Metric {$definition->name} does not declare label(s) ".implode(', ', $unknown).'.');
        }
        $values = [];
        foreach ($definition->labelNames as $label) {
            $values[] = LabelValue::clean($labels[$label] ?? null);
        }

        return $values;
    }
}
