<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('generates a request id when none is sent', function (): void {
    $response = $this->getJson('/api/v1/shared/ping')->assertOk();

    expect(Str::isUuid((string) $response->headers->get('X-Request-Id')))->toBeTrue();
});

it('keeps a well-formed incoming request id', function (): void {
    $this->withHeader('X-Request-Id', 'client-req-12345')
        ->getJson('/api/v1/shared/ping')
        ->assertHeader('X-Request-Id', 'client-req-12345');
});

it('replaces a malformed incoming request id', function (string $bad): void {
    $response = $this->withHeader('X-Request-Id', $bad)->getJson('/api/v1/shared/ping');

    expect($response->headers->get('X-Request-Id'))->not->toBe($bad);
})->with([
    'too short' => 'abc',
    'bad characters' => 'bad id with spaces!!',
    'too long' => str_repeat('a', 65),
]);
