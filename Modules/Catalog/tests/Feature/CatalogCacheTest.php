<?php

declare(strict_types=1);

use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Services\CatalogState;
use Modules\Catalog\Services\CatalogVersions;
use Modules\Catalog\Services\PageCache;
use Tests\Support\CatalogTesting;

beforeEach(function (): void {
    CatalogTesting::configure();
});

it('serves stale arrays and revalidates after the response', function (): void {
    $calls = 0;
    $compute = function () use (&$calls): array {
        return ['revision' => ++$calls];
    };
    $cache = app(PageCache::class);
    expect($cache->remember('swr-test', [], $compute))->toBe(['revision' => 1]);
    expect($cache->remember('swr-test', [], $compute))->toBe(['revision' => 1]);
    $this->travel((int) config('catalog.cache_fresh_seconds') + 1)->seconds();
    expect($cache->remember('swr-test', [], $compute))->toBe(['revision' => 1])->and($calls)->toBe(1);
    app(DeferredCallbackCollection::class)->invoke();
    expect($cache->remember('swr-test', [], $compute))->toBe(['revision' => 2])->and($calls)->toBe(2);
    $this->travelBack();
});

it('skips payload caching for search requests', function (): void {
    $calls = 0;
    $compute = function () use (&$calls): array {
        return ['revision' => ++$calls];
    };
    $cache = app(PageCache::class);
    $cache->remember('list', ['q' => 'term'], $compute);
    $cache->remember('list', ['q' => 'term'], $compute);
    expect($calls)->toBe(2);
});

it('fails open and logs warnings when either named store throws', function (string $failed): void {
    $state = Cache::store('state');
    $payload = Cache::store('cache');
    Cache::shouldReceive('store')->with($failed)->andThrow(new RuntimeException('simulated dependency failure'));
    Cache::shouldReceive('store')->with($failed === 'state' ? 'cache' : 'state')->andReturn($failed === 'state' ? $payload : $state);
    Log::shouldReceive('channel')->with('app')->andReturnSelf();
    Log::shouldReceive('warning')->once();
    expect(app(PageCache::class)->remember('failure-test', [], fn (): array => ['from' => 'database']))->toBe(['from' => 'database']);
})->with(['cache', 'state']);

it('does not store a database result after cold-lock timeout', function (): void {
    config(['catalog.cache_lock_wait_seconds' => 1]);
    $version = app(CatalogVersions::class)->current();
    $key = 'catalog:timeout-test:v'.$version.':'.hash('sha256', json_encode([], JSON_THROW_ON_ERROR));
    $lock = CatalogState::lock('catalog:cold:'.hash('sha256', $key));
    expect($lock->get())->toBeTrue();
    try {
        expect(app(PageCache::class)->remember('timeout-test', [], fn (): array => ['from' => 'database']))->toBe(['from' => 'database']);
        expect(Cache::store('cache')->get($key))->toBeNull();
    } finally {
        $lock->release();
    }
});

it('never serves an old namespace while a committed invalidation is dirty', function (): void {
    $cache = app(PageCache::class);
    $cache->remember('dirty-test', [], fn (): array => ['old' => true]);
    CatalogState::store()->increment(CatalogVersions::DIRTY);
    expect($cache->remember('dirty-test', [], fn (): array => ['old' => false]))->toBe(['old' => false]);
});

it('uses a fresh epoch after a state reset rather than resurrecting an old page', function (): void {
    $cache = app(PageCache::class);
    $cache->remember('reset-test', [], fn (): array => ['old' => true]);
    Cache::store('state')->forget('catalog:versions:epoch');
    expect($cache->remember('reset-test', [], fn (): array => ['old' => false]))->toBe(['old' => false]);
});
