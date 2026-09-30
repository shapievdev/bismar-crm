<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccessLevel;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Support\Auth\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Войти под сотрудником, не выходя из себя, — и вернуться обратно.
 *
 * Правила о том, кому это можно и под кем, живут здесь, а не в политике, — по
 * той же причине, что и правила о самих администраторах (см. SyncUserAccess):
 * сдерживают они ровно того, кого `Gate::before` пропускает не глядя.
 *
 * Их пять, и каждое отвечает на свой вопрос:
 *
 * - **только суперадминистратор.** Администратор проходит проверки прав, но
 *   чужим именем не распоряжается: видеть чужое и быть кем-то другим — разные
 *   вещи;
 * - **не под собой** — это ничего не меняет и путает журнал;
 * - **не под суперадминистратором.** Прав это не прибавит (у него и так всё), а
 *   разобрать потом, кто из двоих что сделал, стало бы нельзя;
 * - **не под уволенным** — платформа для него закрыта, и первый же запрос
 *   ответил бы 401;
 * - **без вложенности.** Из чужого имени в третье не переходят: вернуться можно
 *   только туда, откуда пришёл, и цепочка возвратов превратила бы «вернуться к
 *   себе» в «вернуться неизвестно куда».
 */
final readonly class WorkAsSomebodyElse
{
    public function __construct(private Impersonation $impersonation) {}

    public function start(User $actor, User $target, Request $request): User
    {
        if ($this->impersonation->isActive()) {
            throw new ConflictException('Сначала вернитесь в свою учётную запись.');
        }

        if ($actor->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new ConflictException('Работать от чужого имени может только суперадминистратор.');
        }

        if ($actor->is($target)) {
            throw new ConflictException('Вы и так работаете под собой.');
        }

        if ($target->accessLevel() === AccessLevel::SuperAdmin) {
            throw new ConflictException('Под суперадминистратором работать нельзя.');
        }

        if ($target->isDismissed()) {
            throw new ConflictException('Сотрудник уволен — платформа для него закрыта.');
        }

        $this->impersonation->begin($actor, $target);

        /*
         * Сессия остаётся той же, и это здесь главное: свой вход никуда не
         * девается, а `Auth` начинает отдавать сотрудника. Пересоздавать её
         * нельзя — вместе с нею уехала бы и память о том, кто вошёл на самом
         * деле.
         *
         * Отпечаток пароля в сессии Sanctum переписывать руками не нужно:
         * AuthenticateSession кладёт его после ответа, уже от нового
         * пользователя, — иначе следующий же запрос счёл бы сессию угнанной.
         */
        Auth::guard('web')->login($target);

        return $target;
    }

    /**
     * Вернуться к себе.
     *
     * Если возвращаться некуда — значит и не уходили: отвечаем отказом, а не
     * молча выкидываем человека из его собственной учётной записи.
     */
    public function stop(Request $request): User
    {
        $actor = $this->impersonation->actor();

        if ($actor === null) {
            throw new ConflictException('Вы работаете под собой — возвращаться некуда.');
        }

        $this->impersonation->finish();

        Auth::guard('web')->login($actor);

        return $actor;
    }
}
