<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Symfony\Component\HttpFoundation\Response;

/**
 * IETF draft RateLimit-* headers (RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset in delta-seconds)
 * plus Retry-After on 429. Legacy X-RateLimit-* are gone. When several limiters stack on one route, each
 * only overwrites the headers if its own result is MORE restrictive, so the client sees the tightest one.
 */
final class RateLimitHeaders
{
    public const LIMIT = 'RateLimit-Limit';

    public const REMAINING = 'RateLimit-Remaining';

    public const RESET = 'RateLimit-Reset';

    /** @return array<string, string> */
    public static function fromResult(RateLimitResult $result): array
    {
        $headers = [
            self::LIMIT => (string) $result->limit,
            self::REMAINING => (string) $result->remaining,
            self::RESET => (string) $result->resetAfterSeconds(),
        ];
        if (! $result->allowed) {
            $headers['Retry-After'] = (string) $result->retryAfterSeconds();
        }

        return $headers;
    }

    public static function apply(Response $response, RateLimitResult $result): void
    {
        $existingRemaining = $response->headers->get(self::REMAINING);
        if ($existingRemaining !== null) {
            $existingReset = (int) $response->headers->get(self::RESET, '0');
            $remaining = (int) $existingRemaining;
            $mine = $result->remaining;
            $moreRestrictive = $mine < $remaining || ($mine === $remaining && $result->resetAfterSeconds() > $existingReset);
            if (! $moreRestrictive) {
                return;
            }
        }
        foreach (self::fromResult($result) as $name => $value) {
            $response->headers->set($name, $value);
        }
    }
}
