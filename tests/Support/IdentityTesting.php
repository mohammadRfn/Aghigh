<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Identity\Services\Sessions;

final class IdentityTesting
{
    private static ?string $secret = null;

    private static ?string $public = null;

    /**
     * Fresh in-memory Ed25519 keys (no storage/jwt needed). They are also exported to the
     * environment so child processes (concurrency suite) verify with the same keys.
     */
    public static function configure(bool $arrayState = true): void
    {
        if (self::$secret === null) {
            $pair = sodium_crypto_sign_keypair();
            self::$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
            self::$public = base64_encode(sodium_crypto_sign_publickey($pair));
        }

        foreach (['JWT_PRIVATE_KEY' => self::$secret, 'JWT_PUBLIC_KEY' => self::$public] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        config([
            'identity.jwt.kid' => 'k1',
            'identity.jwt.private_key' => self::$secret,
            'identity.jwt.verification_keys' => ['k1' => self::$public],
            'identity.dummy_hash' => Hash::make('dummy-password-for-tests'),
        ]);

        if ($arrayState) {
            config(['cache.stores.state' => ['driver' => 'array', 'serialize' => false]]);
            Cache::forgetDriver('state');
        }
    }

    /** A real access token: starts a session, so family and jti rows exist. */
    public static function access(User $user): string
    {
        return app(Sessions::class)->start($user, Request::create('/'))->accessToken;
    }

    /**
     * Hand-built JWT for attack tests. A claim value of '__remove__' drops the claim.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $headers
     */
    public static function jwt(User $user, array $claims = [], array $headers = [], string $algorithm = 'EdDSA'): string
    {
        $now = now()->timestamp;
        $payload = array_merge([
            'iss' => (string) config('identity.jwt.issuer'),
            'aud' => [(string) config('identity.jwt.audience')],
            'sub' => $user->id,
            'jti' => (string) Str::uuid7(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
        ], $claims);
        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== '__remove__');

        $header = array_merge(['typ' => 'JWT', 'alg' => $algorithm, 'kid' => 'k1'], $headers);
        $input = self::encode(json_encode($header, JSON_THROW_ON_ERROR)).'.'.self::encode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = match ($algorithm) {
            'none' => '',
            // Algorithm-confusion attempt: HMAC keyed with the public key.
            'HS256' => hash_hmac('sha256', $input, (string) base64_decode((string) self::$public, true), true),
            default => sodium_crypto_sign_detached($input, (string) base64_decode((string) self::$secret, true)),
        };

        return $input.'.'.self::encode($signature);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
