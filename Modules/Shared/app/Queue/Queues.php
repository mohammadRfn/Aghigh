<?php

declare(strict_types=1);

namespace Modules\Shared\Queue;

/**
 * The queue topology (docs/queues.md, ADR 0007). Horizon runs one supervisor per queue; a job pushed
 * to a queue that is not listed here never runs, so always dispatch through these names.
 */
final class Queues
{
    public const ORDERS = 'orders';

    public const BROADCASTS = 'broadcasts';

    public const NOTIFICATIONS = 'notifications';

    public const DEFAULT = 'default';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ORDERS, self::BROADCASTS, self::NOTIFICATIONS, self::DEFAULT];
    }
}
