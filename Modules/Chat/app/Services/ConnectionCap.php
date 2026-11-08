<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Modules\Chat\State\ChatState;
use Modules\Chat\Support\ChatTime;
use Modules\Shared\RateLimiting\LimiterRegistry;

/**
 * APPROXIMATE per-user WebSocket cap. Reverb has no disconnect hook, so the cap is enforced at channel auth over
 * the distinct socket_ids a user authorized recently (`chat:conn:{user}`, score = last seen, pruned on every check).
 * Limits: a socket that never authorizes a private channel is invisible; an entry stays alive only while the
 * client's heartbeat (which carries socket_id) refreshes it, so a closed socket frees its slot after ttl_ms.
 * Max and TTL come from config/rate-limits.php -> concurrency.chat-connections (RATE_LIMIT_MULTIPLIER applies).
 */
final class ConnectionCap
{
    public const NAME = 'chat-connections';

    public function __construct(
        private readonly ChatState $state,
        private readonly LimiterRegistry $registry,
    ) {}

    /** Throws when the state backend is down: channel auth fails closed. */
    public function admit(string $userId, string $socketId): bool
    {
        $cap = $this->registry->concurrency(self::NAME);

        return $this->state->connectionAdmit($userId, $socketId, $cap['max'], $cap['ttl_ms'], ChatTime::nowMs())['admitted'];
    }

    public function refresh(string $userId, string $socketId): bool
    {
        return $this->state->connectionRefresh($userId, $socketId, $this->registry->concurrency(self::NAME)['ttl_ms'], ChatTime::nowMs());
    }

    public function count(string $userId): int
    {
        return $this->state->connectionCount($userId, $this->registry->concurrency(self::NAME)['ttl_ms'], ChatTime::nowMs());
    }

    public static function validSocketId(mixed $socketId): bool
    {
        return is_string($socketId) && preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socketId) === 1;
    }
}
