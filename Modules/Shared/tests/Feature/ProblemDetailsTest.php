<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);

    Route::middleware('api')->prefix('api/v1/_test')->group(function (): void {
        Route::post('validate', fn (Request $request) => $request->validate([
            'name' => ['required', 'string'],
        ]));

        Route::get('boom', fn () => throw new RuntimeException('secret internal detail'));
    });
});

it('renders unknown API routes as problem+json with a request id', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJson(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404])
        ->assertJsonStructure(['detail', 'instance', 'request_id']);

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');
    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('renders validation failures with an errors member', function (): void {
    $response = $this->postJson('/api/v1/_test/validate', [])
        ->assertStatus(422)
        ->assertJson(['status' => 422, 'title' => 'Unprocessable Content'])
        ->assertJsonStructure(['errors' => ['name']]);

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('keeps the Allow header on 405 responses', function (): void {
    $this->getJson('/api/v1/_test/validate')
        ->assertStatus(405)
        ->assertJson(['status' => 405])
        ->assertHeader('Allow');
});

it('hides internals on server errors', function (): void {
    $response = $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJson(['status' => 500, 'detail' => 'An unexpected error occurred.']);

    expect($response->getContent())->not->toContain('secret internal detail');
});
