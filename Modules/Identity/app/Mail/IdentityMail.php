<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Identity\Models\User;
use Modules\Identity\Services\IdentityMessages;

final class IdentityMail extends Mailable
{
    public function __construct(public readonly string $mailId) {}

    public function build(): static
    {
        $message = DB::table('identity_mail_outbox')->find($this->mailId);
        if ($message === null) {
            throw new \RuntimeException('Mail record is missing.');
        }
        $user = User::query()->findOrFail($message->user_id);
        $url = null;
        $title = 'Your Aghigh account already exists';
        $text = 'Someone requested registration with this address. Your existing account has not changed. If this was not you, you can ignore this email.';
        if ($message->purpose === 'verify') {
            $title = 'Verify your Aghigh email';
            $text = 'Confirm your email address to finish creating your account. This link expires in 60 minutes.';
            $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        } elseif ($message->purpose === 'reset') {
            $title = 'Reset your Aghigh password';
            $text = 'Use this link to choose a new password. If you did not request this, ignore this email.';
            $url = rtrim((string) config('identity.frontend_url'), '/').'/reset-password?'.http_build_query([
                'id' => $message->reset_id, 'token' => IdentityMessages::resetToken($message->reset_id),
            ]);
        }

        return $this->subject($title)->view('identity::mail', compact('title', 'text', 'url'));
    }
}
