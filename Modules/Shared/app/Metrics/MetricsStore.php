<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

/** Scrape side of a Metrics driver: the same singleton implements both interfaces. */
interface MetricsStore
{
    /** Text exposition (0.0.4) of everything written through the contract, or null when the backend is unreadable. */
    public function render(): ?string;

    /** Deletes every metric key of this driver (metrics:reset). Never touches another Redis db. */
    public function wipe(): void;

    /** redis | memory | null */
    public function driver(): string;
}
