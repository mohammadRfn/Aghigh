<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Identity\Http\Middleware\RequireOrigin;
use Modules\Shared\Exceptions\ProblemDetails;
use Modules\Shared\Http\Middleware\AssignRequestId;
use Modules\Shared\Http\Middleware\SecurityHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Traefik: trust forwarded headers only when TRUSTED_PROXIES is set
        // ("*" or a comma-separated list of IPs/CIDRs). Unset = trust nobody.
        $proxies = env('TRUSTED_PROXIES');

        if (is_string($proxies) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        // Global, so even unmatched routes (404) get a request id and the headers.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // CSRF defense for cookie endpoints must run before authentication (else 401 hides 403).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireOrigin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => ProblemDetails::render($e, $request),
        );
    })->create();
