<?php

declare(strict_types=1);

namespace Modules\Shared\Metrics;

/**
 * Keeps label values printable and short. It does NOT make an unbounded value bounded: callers must only pass
 * values from a finite set (ADR 0010). Empty values are rendered as "none" so a missing label is visible.
 */
final class LabelValue
{
    public const MAX_LENGTH = 120;

    public static function clean(string|int|float|bool|null $value): string
    {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);
        if ($value === '') {
            return 'none';
        }
        $value = preg_replace('/[^\p{L}\p{N}_.:\/{}\-]+/u', '_', $value) ?? 'invalid';

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    /** Lower-case identifier for enums such as roles and outcomes. */
    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? 'invalid';

        return $value === '' ? 'none' : substr($value, 0, 40);
    }
}
