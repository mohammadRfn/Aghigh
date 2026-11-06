<?php

declare(strict_types=1);

namespace Modules\Shared\RateLimiting;

use Illuminate\Http\Request;

/**
 * Builds limiter keys and their log-safe form. Raw keys (which may contain an IP, a user id or an email tag)
 * only ever reach Redis; logs and events get `hash()`: a truncated HMAC-SHA256 keyed with APP_KEY.
 * Never a plain sha256 of an email (rainbow-table trivial), never raw emails or tokens.
 */
final class RateLimitKey
{
    /** Same construction as Identity's email tag (Task 3) so `auth-login` keys keep their old shape. */
    public static function emailTag(string $email): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key')), 0, 12);
    }

    /** Log/event safe identifier of a key: HMAC(app.key, key) truncated to `rate-limits.hash_length` hex chars. */
    public static function hash(string $key): string
    {
        $length = max(8, (int) config('rate-limits.hash_length', 16));

        return substr(hash_hmac('sha256', $key, (string) config('app.key')), 0, $length);
    }

    /**
     * Key for a named limiter according to its `by` strategy. The IP comes from Request::ip(), which honours
     * X-Forwarded-For ONLY through Laravel's TrustProxies (bootstrap/app.php); nothing here reads headers.
     */
    public static function for(string $limiter, string $by, Request $request): string
    {
        $ip = $request->ip() ?? 'unknown';

        // The user is only resolved for user-keyed limiters: pre-auth limiters must not touch the guard.
        $subject = match ($by) {
            'ip' => 'ip:'.$ip,
            'user' => ($userId = self::userId($request)) !== null ? 'user:'.$userId : 'anon:'.$ip,
            'user+ip' => (($userId = self::userId($request)) !== null ? 'user:'.$userId : 'anon').'|ip:'.$ip,
            'ip+email' => 'ip:'.$ip.'|email:'.self::emailTag(self::emailInput($request)),
            default => throw new \InvalidArgumentException("Unknown rate limit key strategy [{$by}]."),
        };

        return $limiter.'|'.$subject;
    }

    public static function userId(Request $request): ?string
    {
        $user = $request->user('api') ?? $request->user();
        if ($user === null) {
            return null;
        }
        $id = $user->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }

    private static function emailInput(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? $email : '';
    }
}
