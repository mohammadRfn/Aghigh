<?php

declare(strict_types=1);

namespace Modules\Shared\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Shared\Contracts\ProblemException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders API errors as RFC 9457 problem details (application/problem+json).
 * Returns null for non-API requests so Laravel's default rendering applies.
 */
final class ProblemDetails
{
    private const SLUGS = [
        400 => 'bad-request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not-found',
        405 => 'method-not-allowed',
        409 => 'conflict',
        410 => 'gone',
        413 => 'payload-too-large',
        415 => 'unsupported-media-type',
        422 => 'validation-error',
        429 => 'too-many-requests',
        503 => 'service-unavailable',
    ];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        $debug = (bool) config('app.debug');
        $headers = [];
        $extra = [];
        $slug = null;

        if ($e instanceof ProblemException) {
            $status = $e->problemStatus();
            $detail = $e->getMessage();
            $slug = $e->problemType();
            $extra = $e->problemExtensions();
        } elseif ($e instanceof ValidationException) {
            $status = $e->status;
            $detail = 'The request contains invalid fields.';
            $extra['errors'] = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $detail = 'Authentication is required.';
            $headers['WWW-Authenticate'] = 'Bearer';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $detail = $e->getMessage();
        } else {
            $status = 500;
            $detail = $debug ? $e->getMessage() : '';
        }

        // Never leak internals from server errors outside debug mode.
        if ($status >= 500 && ! $debug) {
            $detail = 'An unexpected error occurred.';
        }

        $title = Response::$statusTexts[$status] ?? 'Error';

        if ($detail === '') {
            $detail = $title;
        }

        $slug ??= self::SLUGS[$status] ?? ($status >= 500 ? 'internal-error' : 'client-error');
        $headers['Content-Type'] = 'application/problem+json';

        // Reserved members come first, so extensions can never override them.
        return new JsonResponse([
            'type' => rtrim((string) config('app.url'), '/').'/problems/'.$slug,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => $request->attributes->get('request_id'),
        ] + $extra, $status, $headers);
    }
}