<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\Race;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// One real HTTP request through the full kernel (middleware, guard, limiter, action), released at the barrier.
Race::worker($argv, function (array $arguments): array {
    [$method, $path, $token, $body] = array_pad($arguments, 4, '');
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    $request = Request::create($path, $method, server: $server, content: $body === '' ? null : $body);
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    $decoded = json_decode((string) $response->getContent(), true);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'id' => is_array($decoded) ? ($decoded['data']['id'] ?? null) : null,
        'seq' => is_array($decoded) ? ($decoded['data']['seq'] ?? null) : null,
        'problem' => is_array($decoded) && isset($decoded['type']) ? basename((string) $decoded['type']) : null,
    ];
});
