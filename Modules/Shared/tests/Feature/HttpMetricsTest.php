<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shared\Contracts\Metrics;
use Tests\Support\MetricsTesting;

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
    Route::get('/_t/obs/named/{id}', fn () => response('ok'))->name('t.obs.named');
    Route::get('/_t/obs/unnamed/{id}', fn () => response('ok'));
    Route::get('/_t/obs/unavailable', fn () => response('down', 503))->name('t.obs.unavailable');
});

it('labels a named route by its name, never by the concrete URL', function (): void {
    $this->get('/_t/obs/named/9f1c2d3e-aaaa-bbbb-cccc-0123456789ab')->assertOk();

    expect(MetricsTesting::sample('aghigh_http_requests_total{route="t.obs.named",method="GET",status_class="2xx"}'))->toBe(1.0)
        ->and(MetricsTesting::body())->not->toContain('9f1c2d3e');
});

it('labels an unnamed route by its URI template', function (): void {
    $this->get('/_t/obs/unnamed/12345')->assertOk();

    $body = MetricsTesting::body();
    expect($body)->toContain('route="/_t/obs/unnamed/{id}"')->and($body)->not->toContain('12345');
});

it('uses the status class of the response (5xx)', function (): void {
    $this->get('/_t/obs/unavailable')->assertStatus(503);

    expect(MetricsTesting::sample('aghigh_http_requests_total{route="t.obs.unavailable",method="GET",status_class="5xx"}'))->toBe(1.0);
});

it('records one duration observation per request in the histogram', function (): void {
    $this->get('/_t/obs/named/1')->assertOk();
    $this->get('/_t/obs/named/2')->assertOk();

    expect(MetricsTesting::sample('aghigh_http_request_duration_seconds_count{route="t.obs.named",method="GET"}'))->toBe(2.0)
        ->and(MetricsTesting::sample('aghigh_http_request_duration_seconds_sum{route="t.obs.named",method="GET"}'))->toBeGreaterThanOrEqual(0.0)
        ->and(MetricsTesting::sample('aghigh_http_request_duration_seconds_bucket{route="t.obs.named",method="GET",le="+Inf"}'))->toBe(2.0);
});

it('folds an exotic HTTP method into method="other" and an unrouted request into route="unmatched"', function (): void {
    $this->call('PROPFIND', '/api/v1/shared/ping');

    expect(MetricsTesting::body())->toContain('route="unmatched",method="other",status_class="4xx"');
});

it('does not count /up', function (): void {
    $this->get('/up')->assertOk();

    expect(MetricsTesting::has('aghigh_http_requests_total'))->toBeFalse();
});

it('never breaks a request when the metrics backend throws', function (): void {
    $this->app->instance(Metrics::class, new class implements Metrics
    {
        public function counter(string $name, array $labels = [], float|int $by = 1): void
        {
            throw new RuntimeException('metrics exploded');
        }

        public function gauge(string $name, float|int $value, array $labels = []): void
        {
            throw new RuntimeException('metrics exploded');
        }

        public function histogram(string $name, float|int $value, array $labels = []): void
        {
            throw new RuntimeException('metrics exploded');
        }
    });

    $this->get('/_t/obs/named/1')->assertOk()->assertSee('ok');
});
