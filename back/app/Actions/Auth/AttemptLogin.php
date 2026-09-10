<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Models\User;
use App\Support\Authorization;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class AttemptLogin
{
    /**
     * Failed attempts allowed per phone + IP pair before the login is locked out.
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
        $throttleKey = $this->throttleKey($data->phone, $ip);

        $this->ensureIsNotRateLimited($throttleKey);

        if (! $this->guard()->attempt($data->credentials(), $data->remember)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            // Ошибка вешается на номер: неизвестный номер и неверный пароль
            // неотличимы намеренно — иначе форма входа перебором рассказывала
            // бы, кто в компании работает.
            throw ValidationException::withMessages([
                'phone' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $this->ensureStillEmployed();
    }

    /**
     * Уволенного дальше двери не пускают.
     *
     * Проверка стоит после `Auth::attempt`, а не до него, намеренно: узнать,
     * что учётная запись закрыта, должен только тот, кто и так знает пароль, —
     * иначе форма входа отвечала бы посторонним, кто в компании работает, а
     * кто нет.
     *
     * Сессию, которую attempt только что открыл, приходится закрывать руками:
     * иначе увольнение было бы слышно лишь на следующем запросе.
     *
     * @throws ValidationException
     */
    private function ensureStillEmployed(): void
    {
        $user = $this->guard()->user();

        if (! $user instanceof User || ! $user->isDismissed()) {
            return;
        }

        $this->guard()->logout();

        throw ValidationException::withMessages([
            'phone' => 'Доступ к платформе закрыт: вы больше не числитесь сотрудником.',
        ]);
    }

    /**
     * Вход открывает сессию — и именно сессионный страж, названный по имени.
     *
     * Гвардом по умолчанию тут не обойтись: `auth:sanctum` переписывает его на
     * «sanctum» на весь запрос, и у того же `attempt` попросту нет — см.
     * App\Support\Authorization.
     */
    private function guard(): StatefulGuard
    {
        return Auth::guard(Authorization::GUARD);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        throw ValidationException::withMessages([
            'phone' => __('auth.throttle', [
                'seconds' => $seconds = RateLimiter::availableIn($throttleKey),
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Rate limiting is scoped to the phone + IP pair so one attacker cannot
     * lock a legitimate user out of their own account.
     *
     * Номер к этому месту уже приведён к хранимому виду (см. LoginRequest), так
     * что «8 999…» и «+7 999…» считаются одной и той же попыткой, а не двумя.
     */
    private function throttleKey(string $phone, string $ip): string
    {
        return $phone.'|'.$ip;
    }
}
