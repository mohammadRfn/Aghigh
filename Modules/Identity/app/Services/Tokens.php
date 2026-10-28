<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;
use Psr\Clock\ClockInterface;
use Throwable;

final class Tokens
{
    public function issue(User $user, string $familyId): string
    {
        $kid = (string) config('identity.jwt.kid');
        $keys = config('identity.jwt.verification_keys', []);
        $configuration = Configuration::forAsymmetricSigner(
            $this->signer(), $this->key((string) config('identity.jwt.private_key')), $this->key((string) ($keys[$kid] ?? '')),
        );
        $now = now()->toDateTimeImmutable();
        $expires = $now->modify('+'.max(1, (int) config('identity.jwt.ttl')).' minutes');
        $jti = (string) Str::uuid7();
        $this->isDenied($jti); // No token is issued if security-state Redis is unavailable.
        $token = $configuration->builder()->withHeader('kid', $kid)
            ->issuedBy((string) config('identity.jwt.issuer'))
            ->permittedFor((string) config('identity.jwt.audience'))
            ->relatedTo($user->id)->identifiedBy($jti)
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($expires)
            ->getToken($configuration->signer(), $configuration->signingKey());
        DB::table('identity_access_tokens')->insert([
            'id' => $jti, 'user_id' => $user->id, 'family_id' => $familyId, 'expires_at' => $expires,
        ]);

        return $token->toString();
    }

    public function user(Request $request): ?User
    {
        $raw = $request->bearerToken();
        if ($raw === null || $raw === '') {
            return null;
        }
        $token = $this->validate($raw);
        $jti = $token->claims()->get('jti');
        if ($this->isDenied($jti)) {
            throw $this->invalid();
        }
        // The only user query in the guard: indexed UUID primary-key lookup, no role claim.
        $user = User::query()->find($token->claims()->get('sub'));
        if ($user === null || $user->email_verified_at === null) {
            throw $this->invalid();
        }
        $request->attributes->set('identity.jti', $jti);
        $request->attributes->set('identity.exp', $token->claims()->get('exp')->getTimestamp());

        return $user;
    }

    public function validate(string $raw): UnencryptedToken
    {
        if (strlen($raw) > 8192) {
            throw $this->invalid();
        }
        try {
            $token = (new Parser(new JoseEncoder()))->parse($raw);
            if (! $token instanceof UnencryptedToken || $token->headers()->get('alg') !== 'EdDSA') {
                throw $this->invalid();
            }
            $kid = $token->headers()->get('kid');
            $keys = config('identity.jwt.verification_keys', []);
            if (! is_string($kid) || ! array_key_exists($kid, $keys)) {
                throw $this->invalid();
            }
            $jti = $token->claims()->get('jti');
            $sub = $token->claims()->get('sub');
            if (! is_string($jti) || ! Str::isUuid($jti) || ! is_string($sub) || ! Str::isUuid($sub)) {
                throw $this->invalid();
            }
            $clock = new class implements ClockInterface
            {
                public function now(): DateTimeImmutable
                {
                    return now()->toDateTimeImmutable();
                }
            };
            $valid = (new Validator())->validate($token,
                new SignedWith($this->signer(), $this->key((string) $keys[$kid])),
                new IssuedBy((string) config('identity.jwt.issuer')),
                new PermittedFor((string) config('identity.jwt.audience')),
                new StrictValidAt($clock),
            );
            if (! $valid) {
                throw $this->invalid();
            }

            return $token;
        } catch (IdentityProblem $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->invalid();
        }
    }

    public function deny(string $jti, DateTimeInterface $expires): void
    {
        $remaining = $expires->getTimestamp() - now()->getTimestamp();
        if ($remaining <= 0) {
            return;
        }
        try {
            if (! Cache::store('state')->put('identity:deny:'.$jti, true, $remaining)) {
                throw new \RuntimeException('Security state write failed.');
            }
        } catch (Throwable) {
            throw new IdentityProblem('authentication-unavailable', 503, 'Authentication is temporarily unavailable.');
        }
    }

    public function isDenied(string $jti): bool
    {
        try {
            return Cache::store('state')->get('identity:deny:'.$jti) !== null;
        } catch (Throwable) {
            throw new IdentityProblem('authentication-unavailable', 503, 'Authentication is temporarily unavailable.');
        }
    }

    private function key(string $material): InMemory
    {
        try {
            return str_starts_with($material, '@') ? InMemory::file(substr($material, 1)) : InMemory::base64Encoded($material);
        } catch (Throwable) {
            throw new IdentityProblem('authentication-unavailable', 503, 'Authentication is temporarily unavailable.');
        }
    }

    private function signer(): Eddsa
    {
        if (config('identity.jwt.algorithm') !== 'EdDSA') {
            throw new \LogicException('Only EdDSA is supported by this implementation.');
        }

        return new Eddsa();
    }

    private function invalid(): IdentityProblem
    {
        return new IdentityProblem('invalid-access-token', 401, 'The access token is invalid or expired.');
    }
}
