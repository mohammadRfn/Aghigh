<?php

declare(strict_types=1);

it('adds hardening headers to API responses', function (): void {
    $this->getJson('/api/v1/shared/ping')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS only over https', function (): void {
    // An absolute https URL: a relative path would be rebuilt from APP_URL (http).
    $this->getJson('https://localhost/api/v1/shared/ping')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
