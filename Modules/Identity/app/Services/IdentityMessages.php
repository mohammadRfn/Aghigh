<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Jobs\SendIdentityMail;
use Modules\Identity\Models\User;

final class IdentityMessages
{
    public function queue(User $user, string $purpose, ?string $resetId = null): void
    {
        $id = (string) Str::uuid7();
        DB::table('identity_mail_outbox')->insert([
            'id' => $id, 'user_id' => $user->id, 'purpose' => $purpose,
            'reset_id' => $resetId, 'created_at' => now(),
        ]);
        SendIdentityMail::dispatch($id)->onQueue('default')->afterCommit();
    }

    public static function resetToken(string $id): string
    {
        // Deterministic PRF: retryable ID-only jobs need neither plaintext token storage nor token payloads.
        return hash_hmac('sha256', 'identity-password-reset-v1:'.$id, (string) config('app.key'));
    }

    public function requestReset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            $id = (string) Str::uuid7();
            DB::table('password_reset_tokens')->insert([
                'id' => $id, 'user_id' => $user->id,
                'token_hash' => hash('sha256', self::resetToken($id)),
                'created_at' => now(), 'expires_at' => now()->addMinutes((int) config('identity.reset_ttl')),
            ]);
            $this->queue($user, 'reset', $id);
        }, 3);
    }
}
