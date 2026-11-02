<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Contracts\ProblemHeaders;

it('applies the headers of a problem exception and keeps problem+json', function (): void {
    config(['app.debug' => false, 'app.url' => 'http://localhost:8000']);
    Route::middleware('api')->prefix('api/v1/_test')->group(function (): void {
        Route::get('retry', function (): never {
            throw new class('Try later.') extends RuntimeException implements ProblemException, ProblemHeaders
            {
                public function problemStatus(): int
                {
                    return 409;
                }

                public function problemType(): string
                {
                    return 'busy';
                }

                public function problemExtensions(): array
                {
                    return [];
                }

                public function problemHeaders(): array
                {
                    return ['Retry-After' => '2', 'Content-Type' => 'text/plain'];
                }
            };
        });
    });

    $this->getJson('/api/v1/_test/retry')
        ->assertStatus(409)
        ->assertHeader('Retry-After', '2')
        ->assertHeader('Content-Type', 'application/problem+json');
});
