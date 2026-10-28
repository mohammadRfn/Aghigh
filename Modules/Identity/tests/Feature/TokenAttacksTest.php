<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Identity\Services\Tokens;
use Tests\Support\IdentityTesting;

uses(RefreshDatabase::class);
beforeEach(fn () => IdentityTesting::configure());

it('rejects token attacks as 401 problem json', function (string $attack): void {
    $user = User::factory()->create();
    $token = match ($attack) {
        'alg-none' => IdentityTesting::jwt($user, algorithm: 'none'),
        'algorithm-swap' => IdentityTesting::jwt($user, algorithm: 'HS256'),
        'wrong-issuer' => IdentityTesting::jwt($user, ['iss' => 'untrusted']),
        'wrong-audience' => IdentityTesting::jwt($user, ['aud' => ['untrusted']]),
        'expired' => IdentityTesting::jwt($user, ['iat' => now()->subMinutes(20)->timestamp, 'nbf' => now()->subMinutes(20)->timestamp, 'exp' => now()->subMinute()->timestamp]),
        'future-nbf' => IdentityTesting::jwt($user, ['nbf' => now()->addMinute()->timestamp]),
        'missing-jti' => IdentityTesting::jwt($user, ['jti' => '__remove__']),
        'unknown-kid' => IdentityTesting::jwt($user, headers: ['kid' => 'unknown']),
        default => IdentityTesting::jwt($user),
    };
    if ($attack === 'tampered-payload') {
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(['sub' => $user->id, 'role' => 'admin'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = implode('.', $parts);
    }
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('status', 401);
})->with(['alg-none', 'algorithm-swap', 'tampered-payload', 'wrong-issuer', 'wrong-audience', 'expired', 'future-nbf', 'missing-jti', 'unknown-kid']);

it('rejects a denylisted jti', function (): void {
    $user = User::factory()->create();
    $jti = (string) Str::uuid7();
    $token = IdentityTesting::jwt($user, ['jti' => $jti]);
    app(Tokens::class)->deny($jti, now()->addMinutes(10));
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json');
});

it('accepts a retired verifying key but rejects a removed key', function (): void {
    $user = User::factory()->create();
    $token = IdentityTesting::jwt($user);
    $pair = sodium_crypto_sign_keypair();
    $old = config('identity.jwt.verification_keys.k1');
    config(['identity.jwt.kid' => 'k2', 'identity.jwt.verification_keys' => ['k1' => $old, 'k2' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    config(['identity.jwt.verification_keys' => ['k2' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('loads live roles instead of trusting claims and forgets the previous request user', function (): void {
    $user = User::factory()->create(['role' => 'customer']);
    $token = IdentityTesting::jwt($user, ['role' => 'admin']);
    $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.role', 'customer');
    $user->forceFill(['role' => 'support_agent'])->save();
    $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.role', 'support_agent');
    $this->withHeader('Authorization', '')->getJson('/api/v1/me')->assertStatus(401);
});

it('fails closed when security-state Redis is unavailable', function (): void {
    $user = User::factory()->create();
    $token = IdentityTesting::jwt($user);
    Cache::shouldReceive('store')->with('state')->andThrow(new RuntimeException('unavailable'));
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(503)->assertHeader('Content-Type', 'application/problem+json');
});
