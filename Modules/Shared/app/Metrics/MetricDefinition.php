<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

/** One declared metric: the Catalog is the only place that creates these. */
final readonly class MetricDefinition
{
    /**
     * @param  'counter'|'gauge'|'histogram'  $type
     * @param  list<string>  $labelNames
     * @param  list<float>  $buckets
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $help,
        public array $labelNames = [],
        public array $buckets = [],
    ) {}
}
