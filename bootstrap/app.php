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
use Modules\Shared\Http\Middleware\ThrottleSlidingPreAuth;

/*
 | Task 7: the client IP used by every per-IP limiter comes from Request::ip() and therefore from Laravel's
 | TrustProxies only. Nothing in the app reads X-Forwarded-For by hand. TRUSTED_PROXIES defaults to the
 | compose `edge` network (compose.yaml declares its subnet) where only Traefik lives; set it explicitly
 | per deployment. "*" trusts every peer and is only acceptable when the app port is not reachable directly.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up', // exempt from every limiter: no limiter middleware is attached to it
    )
    // Channel authorization uses the stateless JWT guard (never web/session) and the broadcasting-auth limiter
    // (per user + IP). Echo: authEndpoint '/api/broadcasting/auth'. Task 8 restricts the channels themselves.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:api', 'throttle.sliding:broadcasting-auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $default = '172.30.0.0/24'; // compose.yaml -> networks.edge.ipam
        $proxies = env('TRUSTED_PROXIES', $default);
        $proxies = is_string($proxies) && trim($proxies) !== '' ? trim($proxies) : $default;
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global, so even unmatched routes (404) get a request id and the headers.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // CSRF defense for cookie endpoints must run before authentication (else 401 hides 403).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireOrigin::class);
        // Catalog admin responses (including 401/403) must carry private, no-store: wrap auth.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AdminHeaders::class);
        // Same for Inventory admin (and any route using NoStore): 401/403 must be private, no-store.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, NoStore::class);
        // Pre-auth per-IP limiter: ThrottleSliding cannot be listed (same class also runs post-auth, per user), so
        // the pre-auth variant is its own class. Without this, the priority sort moved auth:api ahead of an unlisted
        // limiter (SubstituteBindings from the api group is listed after auth), so floods reached the JWT guard.
        // Must stay after the NoStore line above so NoStore still wraps it.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ThrottleSlidingPreAuth::class);
        // Post-auth limiters (throttle.sliding / concurrency.cap) are deliberately NOT in the priority list.
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
