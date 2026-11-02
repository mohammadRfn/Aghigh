<?php

declare(strict_types=1);

namespace Modules\Ordering\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Ordering\Exceptions\OrderingProblem;
use Symfony\Component\HttpFoundation\Response;

/** POST /orders requires Idempotency-Key: 16-64 characters from [A-Za-z0-9_-]. Checked before body validation. */
final class RequireIdempotencyKey
{
    public const ATTRIBUTE = 'idempotency_key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $key)) {
            throw new OrderingProblem('The Idempotency-Key header is required: 16 to 64 characters from A-Z, a-z, 0-9, "_" and "-".', 422, 'idempotency-key-invalid');
        }
        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }
}
