<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;
use Modules\Identity\Rules\PasswordPolicy;

final class Accounts
{
    public function __construct(private IdentityMessages $messages) {}

    public static function emailTag(string $email): string
    {
        return substr(hash_hmac('sha256', mb_strtolower($email), (string) config('app.key')), 0, 12);
    }

    public function register(string $name, string $email, string $password): void
    {
        $hash = Hash::make($password); // Also performed for an existing email.
        DB::transaction(function () use ($name, $email, $hash): void {
            $created = DB::table('users')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'name' => $name, 'email' => $email,
                'password' => $hash, 'role' => 'customer', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
            $this->messages->queue($user, $created === 1 ? 'verify' : 'exists');
        }, 3);
    }

    public function login(string $email, string $password, Request $request): SessionTokens
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $dummy = config('identity.dummy_hash');
        if (! is_string($dummy) || $dummy === '') {
            $path = (string) config('identity.dummy_hash_path');
            $dummy = is_readable($path) ? trim((string) file_get_contents($path)) : '';
        }
        if ($dummy === '') {
            throw new IdentityProblem('authentication-unavailable', 503, 'Authentication is temporarily unavailable.');
        }
        $valid = Hash::check($password, $user->password ?? $dummy);
        if (! $valid || $user === null || $user->locked_until?->isFuture()) {
            if ($user !== null) {
                $this->recordFailure($user);
            }
            Log::channel('security')->notice('login_failed', ['email_hash' => self::emailTag($email), 'ip' => $request->ip()]);
            throw new IdentityProblem('invalid-credentials', 401, 'The supplied credentials are invalid.');
        }
        $passwordHash = Hash::needsRehash($user->password) ? Hash::make($password) : $user->password;

        return DB::transaction(function () use ($user, $request, $passwordHash): SessionTokens {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // A concurrent reset or lockout must not be bypassed after the expensive hash check.
            if (! hash_equals($current->password, $user->password) || $current->locked_until?->isFuture()) {
                throw new IdentityProblem('invalid-credentials', 401, 'The supplied credentials are invalid.');
            }
            if ($current->email_verified_at === null) {
                throw new IdentityProblem('email-not-verified', 403, 'Verify your email before signing in.');
            }
            $current->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'password' => $passwordHash])->save();

            return app(Sessions::class)->start($current, $request);
        }, 3);
    }

    private function recordFailure(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $failures = min($current->failed_login_count + 1, 1000000);
            $values = ['failed_login_count' => $failures];
            $threshold = max(1, (int) config('identity.lockout.attempts'));
            if ($failures >= $threshold) {
                $duration = min((int) config('identity.lockout.max'), (int) config('identity.lockout.base') * (2 ** min(20, $failures - $threshold)));
                $values['locked_until'] = now()->addSeconds($duration);
                Log::channel('security')->notice('lockout', ['user_id' => $current->id, 'seconds' => $duration]);
            }
            $current->forceFill($values)->save();
        }, 3);
    }

    public function resetPassword(string $id, string $token, string $password): void
    {
        DB::transaction(function () use ($id, $token, $password): void {
            $row = DB::selectOne('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP RETURNING user_id', [$id, hash('sha256', $token)]);
            if ($row === null) {
                throw new IdentityProblem('invalid-reset-token', 401, 'The reset link is invalid or expired.');
            }
            $user = User::query()->whereKey($row->user_id)->lockForUpdate()->firstOrFail();
            Validator::make(['password' => $password], ['password' => [new PasswordPolicy($user->email)]])->validate();
            app(Sessions::class)->revokeUser($user->id);
            $user->forceFill(['password' => Hash::make($password), 'failed_login_count' => 0, 'locked_until' => null])->save();
            DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            Log::channel('security')->info('password_reset_completed', ['user_id' => $user->id]);
        }, 3);
    }
}
