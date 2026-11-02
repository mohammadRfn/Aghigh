<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Http\Middleware\RequireIdempotencyKey;
use Symfony\Component\HttpFoundation\Response;

it('defaults ordering problems to a 422 validation error without headers', function (): void {
    $problem = new OrderingProblem('Nope.');

    expect($problem->problemStatus())->toBe(422)
        ->and($problem->problemType())->toBe('order-validation')
        ->and($problem->problemExtensions())->toBe([])
        ->and($problem->problemHeaders())->toBe([]);
});

it('carries a custom status type extensions and headers', function (): void {
    $problem = new OrderingProblem('Wait.', 409, 'idempotency-in-progress', ['a' => 1], ['Retry-After' => '1']);

    expect($problem->problemStatus())->toBe(409)
        ->and($problem->problemType())->toBe('idempotency-in-progress')
        ->and($problem->problemExtensions())->toBe(['a' => 1])
        ->and($problem->problemHeaders())->toBe(['Retry-After' => '1']);
});

it('accepts 16 to 64 URL-safe characters as an idempotency key', function (string $key): void {
    $request = Request::create('/api/v1/orders', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => $key]);

    $response = (new RequireIdempotencyKey)->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and($request->attributes->get(RequireIdempotencyKey::ATTRIBUTE))->toBe($key);
})->with([str_repeat('a', 16), str_repeat('Z', 64), 'abc_DEF-123_ghi-456']);

it('rejects a missing or malformed idempotency key before the body is read', function (?string $key): void {
    $server = $key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];
    $request = Request::create('/api/v1/orders', 'POST', server: $server);

    try {
        (new RequireIdempotencyKey)->handle($request, fn (): Response => new Response('ok'));
    } catch (OrderingProblem $problem) {
        expect($problem->problemStatus())->toBe(422)->and($problem->problemType())->toBe('idempotency-key-invalid');

        return;
    }
    $this->fail('Expected an idempotency-key-invalid problem.');
})->with([null, str_repeat('a', 15), str_repeat('a', 65), 'has spaces in it here', 'with/slash/in/the/key', 'ключ-ключ-ключ-ключ']);
