<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\User\RevokeOAuthTokens;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordWasReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Поставить новый пароль по ссылке из письма.
 *
 * Смена пароля здесь — не то же, что смена его в профиле: там человек знает
 * прежний, а здесь он его забыл, и единственное объяснение, почему он его
 * забыл, — не всегда «забыл». Поэтому за новым паролем закрываются все двери,
 * открытые прежним: сессии на других устройствах и токены коннекторов. Иначе
 * смена пароля после того, как его подсмотрели, не меняла бы ничего — тот, кто
 * уже вошёл, остался бы внутри.
 *
 * Сам вход после этого не открывается: человек возвращается на форму и входит
 * заново, уже новым паролем. Войти его отсюда значило бы открыть сессию по
 * ссылке из письма — а письма пересылают.
 */
final readonly class ResetPassword
{
    public function __construct(private RevokeOAuthTokens $revokeTokens) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $person, string $token, string $password): void
    {
        $tokens = Password::broker()->getRepository();

        /*
         * Ссылка не подошла — и причину мы не разбираем: просрочена, уже
         * использована, набрана с опечаткой или выдана на прежнюю почту. Для
         * человека это одно и то же действие — попросить новую, — а разные
         * ответы рассказали бы перебирающему, какие токены существуют.
         *
         * Отказ вешается на поле токена, но показывает его экран целиком: поля
         * этого в форме нет, он приехал ссылкой.
         */
        if (! $tokens->exists($person, $token)) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка больше не работает: она живёт час и срабатывает один раз. Попросите новую.',
            ]);
        }

        DB::transaction(function () use ($person, $password, $tokens): void {
            $person->forceFill([
                'password' => $password,
                // Новая строка «запомнить меня»: прежняя пускала без пароля с
                // того устройства, где её поставили.
                'remember_token' => Str::random(60),
            ])->save();

            $tokens->delete($person);

            $this->closeEveryDoorTheOldPasswordOpened($person);
        });

        // Событие фреймворка — чтобы на смену пароля можно было навесить
        // что-нибудь ещё, не трогая это действие.
        event(new PasswordWasReset($person));
    }

    /**
     * Сессии на других устройствах и токены коннекторов.
     *
     * Сессии лежат в базе, и драйвер проверяется: на стенде с другим
     * хранилищем смена пароля не должна падать на пустом месте — так же
     * устроено увольнение (см. ChangeEmployment::signOutEverywhere).
     */
    private function closeEveryDoorTheOldPasswordOpened(User $person): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $person->getKey())
                ->delete();
        }

        $this->revokeTokens->handle($person);
    }
}
