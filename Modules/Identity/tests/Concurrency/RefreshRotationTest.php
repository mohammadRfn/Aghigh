<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;
use Modules\Identity\Services\Sessions;
use Modules\Identity\Services\Tokens;
use Tests\Support\IdentityTesting;
use Tests\Support\ParallelRefresh;

beforeEach(fn () => IdentityTesting::configure(false));

it('has exactly one winner among twenty real processes and preserves the family', function (): void {
    $user = User::factory()->create();
    $session = app(Sessions::class)->start($user, Request::create('/'));
    $raw = $session->cookie()->getValue();
    $outcomes = collect(ParallelRefresh::run($raw, 20));
    expect($outcomes->where('status', 200)->count())->toBe(1)
        ->and($outcomes->where('error', 'refresh-token-conflict')->count())->toBe(19)
        ->and(DB::table('identity_token_families')->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('refresh_tokens')->whereNull('used_at')->count())->toBe(1);
});

it('revokes the family and live access tokens on reuse after the grace interval', function (): void {
    $user = User::factory()->create();
    $session = app(Sessions::class)->start($user, Request::create('/'));
    $raw = $session->cookie()->getValue();
    app(Sessions::class)->rotate($raw, Request::create('/'));
    DB::table('refresh_tokens')->where('token_hash', hash('sha256', $raw))->update(['used_at' => now()->subSeconds((int) config('identity.refresh.grace') + 1)]);
    try {
        app(Sessions::class)->rotate($raw, Request::create('/'));
        $this->fail('Reused refresh token was accepted.');
    } catch (IdentityProblem $exception) {
        expect($exception->problemType())->toBe('refresh-token-reused');
    }
    expect(DB::table('identity_token_families')->whereNotNull('revoked_at')->count())->toBe(1);
    foreach (DB::table('identity_access_tokens')->get() as $access) {
        expect(app(Tokens::class)->isDenied($access->id))->toBeTrue();
    }
});

it('stores denylist TTL on the state connection and never the disposable cache', function (): void {
    $jti = (string) Str::uuid7();
    app(Tokens::class)->deny($jti, now()->addMinute());
    $store = Cache::store('state')->getStore();
    expect($store)->toBeInstanceOf(RedisStore::class);
    $ttl = Redis::connection('state')->ttl($store->getPrefix().'identity:deny:'.$jti);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(Cache::store('redis')->has('identity:deny:'.$jti))->toBeFalse();
});
