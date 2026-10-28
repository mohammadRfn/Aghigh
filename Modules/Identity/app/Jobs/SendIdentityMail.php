<?php

declare(strict_types=1);

namespace Modules\Identity\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Mail\IdentityMail;
use Modules\Identity\Models\User;
use Throwable;

final class SendIdentityMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public readonly string $mailId)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 90];
    }

    public function handle(): void
    {
        Cache::store('state')->lock('identity:mail:'.$this->mailId, 45)->block(3, function (): void {
            $message = DB::table('identity_mail_outbox')->find($this->mailId);
            if ($message === null || $message->sent_at !== null) {
                return;
            }
            $user = User::query()->find($message->user_id);
            if ($user === null) {
                return;
            }
            if ($message->purpose === 'reset' && ! DB::table('password_reset_tokens')->where('id', $message->reset_id)->whereNull('used_at')->where('expires_at', '>', now())->exists()) {
                DB::table('identity_mail_outbox')->where('id', $this->mailId)->update(['sent_at' => now()]);

                return;
            }
            Mail::to($user->email)->send(new IdentityMail($this->mailId));
            DB::table('identity_mail_outbox')->where('id', $this->mailId)->update(['sent_at' => now()]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('security')->error('identity_mail_failed', ['mail_id' => $this->mailId]);
    }
}
