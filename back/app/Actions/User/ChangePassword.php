<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Support\Authorization;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Replaces a user's own password and withdraws the old one from everywhere it
 * is still being honoured.
 *
 * Whether the person knows their current password is the caller's business —
 * this only carries the change out.
 */
final readonly class ChangePassword
{
    public function handle(User $user, #[\SensitiveParameter] string $password): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard(Authorization::GUARD);

        // Read before the token is replaced: replacing it is what invalidates
        // the cookie this asks about.
        $isRemembered = request()->cookies->has($guard->getRecallerName());

        // `password` is cast to `hashed`, so the plain text never reaches the
        // database. `remember_token` is deliberately not fillable.
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        // Sessions elsewhere fall on their own next request: Sanctum's
        // AuthenticateSession compares the hash it put in the session against
        // the one on the record and signs out whoever holds a stale one. A
        // device that ticked "remember me" would not — its cookie is matched
        // against `remember_token`, which a new password does not touch —
        // hence the fresh token above.
        //
        // Signing in again hands this browser the replacement, and moves the
        // session to a new id while it is at it.
        if ($isRemembered) {
            $guard->login($user, remember: true);
        }
    }
}
