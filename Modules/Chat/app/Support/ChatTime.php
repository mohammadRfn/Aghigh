<?php

declare(strict_types=1);

namespace Modules\Chat\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Microsecond timestamps for timestamptz(6) columns (the query grammar would drop the fraction) and wire format. */
final class ChatTime
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function db(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d H:i:s.uP');
    }

    public static function ms(DateTimeInterface|string $time): int
    {
        $time = is_string($time) ? CarbonImmutable::parse($time) : CarbonImmutable::instance($time);

        return (int) floor(((float) $time->format('U.u')) * 1000);
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** ISO 8601, UTC, milliseconds: 2026-06-01T10:00:00.123Z */
    public static function wire(DateTimeInterface|string|null $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        $time = is_string($time) ? CarbonImmutable::parse($time) : CarbonImmutable::instance($time);

        return $time->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
