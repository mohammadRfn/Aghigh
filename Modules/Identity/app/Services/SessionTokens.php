<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Cookie;

/** Internal result; never serialize this object as JSON. */
final readonly class SessionTokens
{
    public function __construct(public string $accessToken, private string $refreshToken, private CarbonInterface $refreshExpiresAt) {}

    public function cookie(): Cookie
    {
        return new Cookie(
            (string) config('identity.refresh.cookie'), $this->refreshToken, $this->refreshExpiresAt,
            (string) config('identity.refresh.path'), null, app()->environment('production'), true, false, Cookie::SAMESITE_STRICT,
        );
    }

    public static function forgetCookie(): Cookie
    {
        return new Cookie(
            (string) config('identity.refresh.cookie'), '', now()->subYear(),
            (string) config('identity.refresh.path'), null, app()->environment('production'), true, false, Cookie::SAMESITE_STRICT,
        );
    }
}
