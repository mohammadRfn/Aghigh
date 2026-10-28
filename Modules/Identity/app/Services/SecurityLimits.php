<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Shared\Exceptions\ProblemDetails;

final class SecurityLimits
{
    public static function register(): void
    {
        foreach (['login', 'register', 'forgot-password'] as $name) {
            RateLimiter::for($name, static function (Request $request) use ($name): Limit {
                $email = $request->input('email');
                $key = $name.'|'.$request->ip();
                if ($name === 'login') {
                    $key .= '|'.Accounts::emailTag(is_string($email) ? trim($email) : '');
                }

                return Limit::perMinute(max(1, (int) config('identity.rates.'.$name)))->by($key)
                    ->response(static function (Request $request, array $headers) {
                        $response = ProblemDetails::render(new IdentityProblem('too-many-requests', 429, 'Too many requests. Try again later.'), $request);

                        return $response?->header('Retry-After', (string) ($headers['Retry-After'] ?? 60));
                    });
            });
        }
    }
}
