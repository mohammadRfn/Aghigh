<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Catalog\Http\Middleware\AdminHeaders;
use Modules\Identity\Http\Middleware\RequireOrigin;
use Modules\Shared\Exceptions\ProblemDetails;
use Modules\Shared\Http\Middleware\AssignRequestId;
use Modules\Shared\Http\Middleware\NoStore;
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
        // Catalog admin responses (including 401/403) must carry private, no-store: wrap auth.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AdminHeaders::class);
        // Same for Inventory admin (and any route using NoStore): 401/403 must be private, no-store.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, NoStore::class);
        // API only: there is no "login" web route. Laravel's default guest redirect calls route('login'),
        // which turned a missing token without an Accept header into a 500 instead of a 401.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => ProblemDetails::render($e, $request),
        );
    })->create();
