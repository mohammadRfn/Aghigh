<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;

final class Sessions
{
    public function __construct(private Tokens $tokens) {}

    public function start(User $user, Request $request): SessionTokens
    {
        return DB::transaction(function () use ($user, $request): SessionTokens {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $familyId = (string) Str::uuid7();
            $absolute = now()->addDays(max(1, (int) config('identity.refresh.absolute_days')));
            DB::table('identity_token_families')->insert([
                'id' => $familyId, 'user_id' => $user->id, 'created_at' => now(), 'expires_at' => $absolute,
            ]);

            return $this->issue($user, $familyId, $absolute, null, $request);
        }, 3);
    }

    public function rotate(string $raw, Request $request): SessionTokens
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $raw)) {
            throw $this->invalid();
        }
        $hash = hash('sha256', $raw);
        $result = DB::transaction(function () use ($hash, $request): SessionTokens|IdentityProblem {
            // This statement, not a PHP read/check/write, chooses the sole rotation winner.
            $parent = DB::selectOne(
                'UPDATE refresh_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP RETURNING *',
                [$hash], false,
            );
            if ($parent === null) {
                return $this->reuse($hash);
            }
            $user = User::query()->whereKey($parent->user_id)->lockForUpdate()->first();
            $family = DB::table('identity_token_families')->where('id', $parent->family_id)->lockForUpdate()->first();
            if ($user === null || $user->email_verified_at === null || $family === null || $family->revoked_at !== null || Carbon::parse($family->expires_at)->isPast()) {
                return $this->invalid();
            }

            return $this->issue($user, $family->id, Carbon::parse($family->expires_at), $parent->id, $request);
        }, 3);
        // Throw only AFTER commit: reuse revocation must never be rolled back by an exception.
        if ($result instanceof IdentityProblem) {
            throw $result;
        }

        return $result;
    }

    public function logout(string $jti, string $userId): void
    {
        $access = DB::table('identity_access_tokens')->where('id', $jti)->where('user_id', $userId)->first();
        if ($access === null) {
            throw $this->invalid();
        }
        $this->revokeFamily($access->family_id);
    }

    public function revokeFamily(string $familyId): void
    {
        DB::transaction(function () use ($familyId): void {
            $family = DB::table('identity_token_families')->find($familyId);
            if ($family === null) {
                return;
            }
            // All new issuance and revocation synchronize on the same user row.
            User::query()->whereKey($family->user_id)->lockForUpdate()->firstOrFail();
            DB::table('identity_token_families')->where('id', $familyId)->update(['revoked_at' => now()]);
            DB::table('refresh_tokens')->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            foreach (DB::table('identity_access_tokens')->where('family_id', $familyId)->where('expires_at', '>', now())->cursor() as $access) {
                // Redis writes precede SQL commit: a Redis outage rolls SQL back and fails closed.
                $this->tokens->deny($access->id, Carbon::parse($access->expires_at));
            }
        }, 3);
    }

    public function revokeUser(string $userId): void
    {
        DB::transaction(function () use ($userId): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $ids = DB::table('identity_token_families')->where('user_id', $userId)->whereNull('revoked_at')->pluck('id');
            foreach ($ids as $id) {
                $this->revokeFamily($id);
            }
        }, 3);
    }

    private function reuse(string $hash): IdentityProblem
    {
        $parent = DB::table('refresh_tokens')->where('token_hash', $hash)->first();
        if ($parent === null || $parent->used_at === null || $parent->revoked_at !== null) {
            return $this->invalid();
        }
        if (Carbon::parse($parent->used_at)->greaterThan(now()->subSeconds(max(0, (int) config('identity.refresh.grace'))))) {
            return new IdentityProblem('refresh-token-conflict', 401, 'Another request already rotated this token.');
        }
        $this->revokeFamily($parent->family_id);
        Log::channel('security')->warning('refresh_reuse_detected', ['user_id' => $parent->user_id, 'family_id' => $parent->family_id]);

        return new IdentityProblem('refresh-token-reused', 401, 'This token family has been revoked. Sign in again.');
    }

    private function issue(User $user, string $familyId, Carbon $absolute, ?string $parentId, Request $request): SessionTokens
    {
        $raw = bin2hex(random_bytes(32));
        $idle = now()->addDays(max(1, (int) config('identity.refresh.idle_days')));
        $expires = $idle->lessThan($absolute) ? $idle : $absolute;
        DB::table('refresh_tokens')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'family_id' => $familyId,
            'parent_id' => $parentId, 'token_hash' => hash('sha256', $raw),
            'issued_at' => now(), 'expires_at' => $expires,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);

        return new SessionTokens($this->tokens->issue($user, $familyId), $raw, $expires);
    }

    private function invalid(): IdentityProblem
    {
        return new IdentityProblem('invalid-refresh-token', 401, 'The refresh token is invalid or expired.');
    }
}
