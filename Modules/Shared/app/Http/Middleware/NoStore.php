<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Marks the answer private, no-store. Put it before auth so 401/403 problem responses carry the
 * header too: errors are rendered here, then stamped.
 */
final class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $handler = app(ExceptionHandler::class);
            $handler->report($error);
            $response = $handler->render($request, $error);
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
