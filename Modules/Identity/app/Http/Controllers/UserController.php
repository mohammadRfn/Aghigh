<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Modules\Identity\Enums\Role;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Models\User;

final class UserController
{
    /** Read your current profile. */
    public function me(Request $request): JsonResponse
    {
        return $this->profile($this->actor($request));
    }

    /** Read a profile. Self or admin only; other authenticated callers receive 403. */
    public function show(Request $request, string $user): JsonResponse
    {
        $actor = $this->actor($request);
        if ($actor->id !== $user && $actor->role !== Role::Admin) {
            throw new IdentityProblem('forbidden', 403, 'You cannot access this user.');
        }

        return $this->profile(User::query()->findOrFail($user));
    }

    /** Change a role. Admin only; every change is audit logged. */
    public function role(Request $request, string $user): JsonResponse
    {
        $actor = $this->actor($request);
        if ($actor->role !== Role::Admin) {
            throw new IdentityProblem('forbidden', 403, 'Administrator access is required.');
        }
        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $target = DB::transaction(function () use ($actor, $user, $data): User {
            $target = User::query()->whereKey($user)->lockForUpdate()->firstOrFail();
            $previous = $target->role->value;
            $target->forceFill(['role' => $data['role']])->save();
            Log::channel('audit')->info('user_role_changed', [
                'actor_id' => $actor->id, 'user_id' => $target->id, 'from' => $previous, 'to' => $data['role'],
            ]);

            return $target;
        }, 3);

        return $this->profile($target);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new IdentityProblem('unauthenticated', 401, 'Authentication is required.');
        }

        return $user;
    }

    private function profile(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->role->value, 'email_verified' => $user->email_verified_at !== null,
        ]]);
    }
}
