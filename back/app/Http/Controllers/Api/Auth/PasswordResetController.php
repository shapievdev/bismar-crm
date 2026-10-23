<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ResetPassword;
use App\Actions\Auth\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Забытый пароль: попросить ссылку и поставить новый.
 *
 * Два адреса и ни одного права: до входа прав ещё нет. Защищает их не
 * проверка, а предел частоты на маршруте, одинаковый ответ на любую просьбу и
 * сама ссылка — одноразовая, часовая и ушедшая на чужой для просящего ящик.
 */
final class PasswordResetController extends Controller
{
    /**
     * Попросить ссылку.
     *
     * Ответ один на все случаи — и это главное здесь. Нашли сотрудника или нет,
     * записана ли у него почта, просил ли он ссылку минуту назад — форма
     * отвечает одинаково, иначе перебором по ней можно было бы узнать, кто в
     * компании работает. Что случилось на самом деле, знает журнал приложения.
     */
    public function store(ForgotPasswordRequest $request, SendPasswordResetLink $sendLink): JsonResponse
    {
        $sendLink->handle($request->login());

        return response()->json(['message' => implode(' ', [
            'Если такая учётная запись есть и к ней записана почта, письмо со ссылкой уже отправлено.',
            'Ссылка живёт час.',
            'Письма нет — посмотрите в «Спаме» или попросите администратора сменить вам пароль.',
        ])]);
    }

    /**
     * Поставить новый пароль.
     *
     * Сотрудник ищется среди работающих: ссылка выдаётся только им, но между
     * письмом и переходом по нему человека могли уволить. Отказ при этом тот же,
     * что и на негодную ссылку, — объяснять уволенному через форму смены пароля,
     * что он уволен, незачем: об этом ему говорит вход.
     *
     * @throws ValidationException
     */
    public function update(ResetPasswordRequest $request, ResetPassword $resetPassword): JsonResponse
    {
        $person = User::query()
            ->employed()
            ->whereKey((int) $request->validated('user'))
            ->first();

        if (! $person instanceof User) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка больше не работает: она живёт час и срабатывает один раз. Попросите новую.',
            ]);
        }

        $resetPassword->handle(
            $person,
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return response()->json(['message' => 'Пароль изменён. Теперь войдите с ним — по номеру телефона.']);
    }
}
