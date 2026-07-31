<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AttemptLogin
{
    /**
     * Failed attempts allowed per email + IP pair before the login is locked out.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Log the user into the session guard.
     *
     * @throws ValidationException When the credentials are wrong or the client is rate limited.
     */
    public function handle(LoginData $data, string $ip): void
    {
        $throttleKey = $this->throttleKey($data->email, $ip);

        $this->ensureIsNotRateLimited($throttleKey, $data->email);

        if (! Auth::attempt($data->credentials(), $data->remember)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $throttleKey, string $email): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds = RateLimiter::availableIn($throttleKey),
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Rate limiting is scoped to the email + IP pair so one attacker cannot
     * lock a legitimate user out of their own account.
     */
    private function throttleKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }
}
