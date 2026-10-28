<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;
use Modules\Identity\Rules\PasswordPolicy;
use Modules\Identity\Services\Accounts;
use Modules\Identity\Services\IdentityMessages;
use Modules\Identity\Services\Sessions;
use Modules\Identity\Services\SessionTokens;

/** Identity authentication. Refresh credentials are cookies, never response body fields. */
final class AuthController
{
    /** Register an account. A valid request always returns the same 202 response.
     * @unauthenticated
     */
    public function register(Request $request, Accounts $accounts): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy($email)],
        ]);
        $accounts->register($data['name'], $email, $data['password']);

        return $this->accepted();
    }

    /** Verify email with a temporary signed URL.
     * @unauthenticated
     */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->email), $hash)) {
            throw new IdentityProblem('invalid-verification-link', 403, 'The verification link is invalid.');
        }
        User::query()->whereKey($id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Email verified.']);
    }

    /** Request a verification email without disclosing account existence.
     * @unauthenticated
     */
    public function resend(Request $request, IdentityMessages $messages): JsonResponse
    {
        $email = $this->email($request);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user !== null && $user->email_verified_at === null) {
            DB::transaction(fn () => $messages->queue($user, 'verify'));
        }

        return $this->accepted();
    }

    /** Sign in. Returns a short-lived access JWT and sets an HttpOnly refresh cookie.
     * @unauthenticated
     */
    public function login(Request $request, Accounts $accounts): JsonResponse
    {
        $email = $this->email($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:128']]);

        return $this->tokens($accounts->login($email, $data['password'], $request));
    }

    /** Rotate the refresh cookie. Requires an explicitly allowed Origin header.
     * @unauthenticated
     */
    public function refresh(Request $request, Sessions $sessions): JsonResponse
    {
        return $this->tokens($sessions->rotate((string) $request->cookie((string) config('identity.refresh.cookie'), ''), $request));
    }

    /** Revoke the current token family and remove the refresh cookie. */
    public function logout(Request $request, Sessions $sessions): JsonResponse
    {
        $sessions->logout((string) $request->attributes->get('identity.jti'), (string) $request->user()?->getAuthIdentifier());

        return response()->json(['message' => 'Signed out.'])->withCookie(SessionTokens::forgetCookie());
    }

    /** Request a password reset. The response never discloses account existence.
     * @unauthenticated
     */
    public function forgot(Request $request, IdentityMessages $messages): JsonResponse
    {
        $email = $this->email($request);
        Log::channel('security')->info('password_reset_requested', ['email_hash' => Accounts::emailTag($email)]);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user !== null) {
            $messages->requestReset($user);
        }

        return $this->accepted();
    }

    /** Consume a one-use reset token and revoke every existing token family.
     * @unauthenticated
     */
    public function reset(Request $request, Accounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'], 'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $accounts->resetPassword($data['id'], $data['token'], $data['password']);

        return response()->json(['message' => 'Password reset. Sign in again.'])->withCookie(SessionTokens::forgetCookie());
    }

    private function email(Request $request): string
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return mb_strtolower(trim($data['email']));
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['message' => 'If action is needed, an email will be sent.'], 202);
    }

    private function tokens(SessionTokens $tokens): JsonResponse
    {
        return response()->json([
            'access_token' => $tokens->accessToken, 'token_type' => 'Bearer',
            'expires_in' => (int) config('identity.jwt.ttl') * 60,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache')->withCookie($tokens->cookie());
    }
}
