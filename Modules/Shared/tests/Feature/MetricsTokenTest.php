<?php

declare(strict_types=1);

const TOKEN_UNDER_TEST = 'token-under-test-0123456789abcdef';

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory', 'shared.metrics.token' => TOKEN_UNDER_TEST]);
});

it('does not accept the token in a query string, a cookie or a non-Bearer scheme', function (): void {
    $this->get('/internal/metrics?token='.TOKEN_UNDER_TEST)->assertStatus(401);
    $this->get('/internal/metrics?access_token='.TOKEN_UNDER_TEST)->assertStatus(401);
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('metrics:'.TOKEN_UNDER_TEST)])->get('/internal/metrics')->assertStatus(401);
    $this->withHeaders(['X-Metrics-Token' => TOKEN_UNDER_TEST])->get('/internal/metrics')->assertStatus(401);
});

it('rejects an empty bearer value and a token that is only a prefix of the real one', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer '])->get('/internal/metrics')->assertStatus(401);
    $this->withHeaders(['Authorization' => 'Bearer '.substr(TOKEN_UNDER_TEST, 0, 10)])->get('/internal/metrics')->assertStatus(401);
});

it('answers 401 with a challenge, no body and no session cookie', function (): void {
    $response = $this->get('/internal/metrics')->assertStatus(401);

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="metrics"')
        ->and($response->headers->getCookies())->toBe([]);
});

it('accepts only GET', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer '.TOKEN_UNDER_TEST])->post('/internal/metrics')->assertStatus(405);
    $this->withHeaders(['Authorization' => 'Bearer '.TOKEN_UNDER_TEST])->get('/internal/metrics')->assertOk();
});

it('exposes the scrape health gauges and sets no cookie on success', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.TOKEN_UNDER_TEST])->get('/internal/metrics')->assertOk();

    expect($response->getContent())->toContain('aghigh_metrics_backend_up 1')->toContain('aghigh_metrics_series ')
        ->and($response->headers->getCookies())->toBe([]);
});
