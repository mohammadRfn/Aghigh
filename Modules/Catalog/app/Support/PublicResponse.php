<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicResponse
{
    /** @param array<string, mixed> $payload */
    public static function make(Request $request, array $payload): JsonResponse
    {
        $response = response()->json($payload);
        $response->headers->set('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
        $response->setEtag(hash('sha256', (string) $response->getContent()), true);
        $response->isNotModified($request);

        return $response;
    }
}
