<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Shared\Contracts\Metrics;
use Modules\Shared\Metrics\Failures;
use Modules\Shared\Metrics\MetricsStore;
use Modules\Shared\Metrics\Names;

/*
 | Task 9: /internal/metrics. These tests run with METRICS_DRIVER=memory (Makefile test line, CI quality step,
 | phpunit.xml): one in-memory registry per test process, wiped by the fresh app of every test.
 */

const METRICS_TEST_TOKEN = 'test-metrics-token-0123456789';

function metricsRequest(?string $token = METRICS_TEST_TOKEN): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return test()->get('/internal/metrics', $headers);
}

function metricsSeriesCount(string $body): int
{
    return count(array_filter(preg_split('/\R/', $body) ?: [], fn (string $line): bool => $line !== '' && $line[0] !== '#'));
}

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory', 'shared.metrics.token' => METRICS_TEST_TOKEN]);
    Failures::reset();
});

it('answers 404 when METRICS_TOKEN is empty (endpoint disabled, never open)', function (): void {
    config(['shared.metrics.token' => '']);

    metricsRequest()->assertNotFound();
    metricsRequest(null)->assertNotFound();
});

it('rejects a missing token with 401 and no body', function (): void {
    metricsRequest(null)->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="metrics"')->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects a wrong token with 401', function (): void {
    metricsRequest('wrong-token-0123456789')->assertStatus(401);
    metricsRequest(METRICS_TEST_TOKEN.'x')->assertStatus(401);
});

it('serves the text exposition with the right token, no-store and the 0.0.4 content type', function (): void {
    $this->get('/api/v1/shared/ping')->assertOk();

    $response = metricsRequest()->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private');

    $body = $response->getContent();
    expect($body)->toContain('# TYPE '.Names::HTTP_REQUESTS.' counter')
        ->and($body)->toContain(Names::HTTP_REQUESTS.'{route="api.v1.shared.ping",method="GET",status_class="2xx"} 1')
        ->and($body)->toContain('# TYPE '.Names::HTTP_DURATION.' histogram')
        ->and($body)->toContain(Names::SCRAPE_BACKEND_UP.' 1')
        ->and($body)->toContain(Names::SCRAPE_SERIES.' ')
        ->and($body)->not->toContain('route="internal.metrics"');
});

it('labels unmatched routes with the fixed value unmatched and never the raw path', function (): void {
    $this->get('/definitely/not/a/route/'.Str::random(12))->assertNotFound();

    $body = metricsRequest()->getContent();
    expect($body)->toContain('route="unmatched",method="GET",status_class="4xx"')
        ->and($body)->not->toContain('definitely/not/a/route');
});

it('keeps the number of series constant across 200 random unknown paths', function (): void {
    $this->get('/warm/'.Str::random(8))->assertNotFound();
    $before = metricsSeriesCount((string) metricsRequest()->getContent());

    for ($i = 0; $i < 200; $i++) {
        $this->get('/'.Str::random(random_int(4, 24)).'/'.Str::uuid())->assertNotFound();
    }

    $after = metricsSeriesCount((string) metricsRequest()->getContent());
    expect($after)->toBe($before)->and($before)->toBeGreaterThan(0);
});

it('does not count /up or /internal/metrics', function (): void {
    $this->get('/up')->assertOk();
    metricsRequest()->assertOk();

    $body = (string) metricsRequest()->getContent();
    expect($body)->not->toContain('route="/up"')->and($body)->not->toContain('internal.metrics');
});

it('exposes aghigh_metrics_enabled 0 and records nothing when METRICS_ENABLED=false', function (): void {
    config(['shared.metrics.enabled' => false]);
    $this->app->forgetInstance(Metrics::class);
    $this->app->forgetInstance(MetricsStore::class);

    $this->get('/api/v1/shared/ping')->assertOk();
    $body = (string) metricsRequest()->assertOk()->getContent();

    expect($body)->toContain(Names::SCRAPE_ENABLED.' 0')->and($body)->not->toContain(Names::HTTP_REQUESTS.'{');
});

it('never fails a request when the metrics Redis is unreachable (redis driver)', function (): void {
    config([
        'shared.metrics.driver' => 'redis',
        'database.redis.metrics.host' => '127.0.0.1',
        'database.redis.metrics.port' => 1, // nothing listens here
        'database.redis.metrics.timeout' => 0.2,
    ]);
    $this->app->forgetInstance('redis'); // RedisManager copies database.redis at construction: rebuild it
    $this->app->forgetInstance(Metrics::class);
    $this->app->forgetInstance(MetricsStore::class);

    $this->get('/api/v1/shared/ping')->assertOk();
    app(Metrics::class)->counter(Names::ORDERS, ['outcome' => 'paid']);

    expect(Failures::total())->toBeGreaterThan(0);

    $body = (string) metricsRequest()->assertOk()->getContent();
    expect($body)->toContain(Names::SCRAPE_BACKEND_UP.' 0');
});

it('refuses metrics:reset in production and wipes otherwise', function (): void {
    app(Metrics::class)->counter(Names::ORDERS, ['outcome' => 'paid']);
    expect((string) metricsRequest()->getContent())->toContain(Names::ORDERS.'{outcome="paid"} 1');

    $this->artisan('metrics:reset')->assertExitCode(0);
    expect((string) metricsRequest()->getContent())->not->toContain(Names::ORDERS.'{outcome="paid"} 1');

    $this->app['env'] = 'production';
    $this->artisan('metrics:reset')->assertExitCode(1);
});

it('rejects unknown metrics and undeclared labels in the memory driver so tests catch typos', function (): void {
    expect(fn () => app(Metrics::class)->counter('aghigh_nope_total'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(Metrics::class)->counter(Names::ORDERS, ['user_id' => '42']))->toThrow(InvalidArgumentException::class);
});
