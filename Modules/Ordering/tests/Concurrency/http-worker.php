<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\Race;

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Arguments: METHOD PATH TOKEN [JSON body] [Idempotency-Key]. Sends one real request through the HTTP kernel.
Race::worker($argv, function (array $arguments): array {
    [$method, $path, $token, $body, $key] = array_pad($arguments, 5, '');
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    if ($key !== '') {
        $server['HTTP_IDEMPOTENCY_KEY'] = $key;
    }
    $request = Request::create($path, $method, server: $server, content: $body === '' ? null : $body);

    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    $decoded = json_decode((string) $response->getContent(), true);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'replayed' => $response->headers->get('Idempotency-Replayed') === 'true',
        'retry_after' => $response->headers->get('Retry-After'),
        'order_id' => is_array($decoded) ? ($decoded['data']['id'] ?? null) : null,
        'problem' => is_array($decoded) && isset($decoded['type']) ? basename((string) $decoded['type']) : null,
    ];
});
