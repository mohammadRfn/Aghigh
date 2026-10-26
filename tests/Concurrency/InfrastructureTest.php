<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('runs on a dedicated Postgres test database', function (): void {
    expect(DB::selectOne('select current_database() as db')->db)->toEndWith('_test');
});

it('has a state Redis connection that never evicts keys', function (): void {
    $policy = Redis::connection('state')->config('GET', 'maxmemory-policy');

    expect(array_values($policy)[0])->toBe('noeviction');
});

it('keeps locks and the rate limiter on the state store', function (): void {
    expect(config('cache.limiter'))->toBe('state');

    $lock = Cache::store('state')->lock('infra-test', 5);

    expect($lock->get())->toBeTrue()
        ->and(Cache::store('state')->lock('infra-test', 5)->get())->toBeFalse();

    $lock->release();
});