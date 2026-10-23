<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Support\Analytics\AnalyticsUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Общее у всех инструментов MCP: от чьего имени и с какими правами.
 *
 * Коннектор не приносит с собой полномочий. Он приходит с токеном сотрудника, и
 * может ровно то, что может сам сотрудник: каждый инструмент спрашивает то же
 * право, которым закрыт соответствующий адрес HTTP-API, и зовёт те же действия
 * (App\Actions\**) и те же отчёты (App\Support\**), что и экран. Нового пути в
 * обход правил здесь нет ни одного — иначе «доступ по роли» означал бы «доступ
 * по роли, кроме случаев, когда просит машина».
 *
 * Спрашивается право дважды: в `shouldRegister` — чтобы инструмента, которым
 * человеку нельзя пользоваться, он просто не видел, — и в `handle`, уже всерьёз.
 * Одной проверки на выдаче не хватает: список инструментов клиент кэширует, и
 * вызов приходит по памяти о прошлом разговоре, когда прав могло быть больше.
 *
 * `permit*` заодно пишет в журнал (канал `mcp`): кто, чем и с какими доводами
 * воспользовался. Без него разобрать потом, что в платформе сделал коннектор, а
 * что человек руками, было бы нечем — действия-то одни и те же.
 */
abstract class BismarTool extends Tool
{
    /**
     * Кто подключён.
     *
     * @throws AuthenticationException
     */
    protected function actor(): User
    {
        $user = auth()->user();

        // Сюда доходят только через `auth:api`, так что гостя тут не бывает.
        // Проверка — на случай, если инструмент позовут из консоли или теста
        // без входа: лучше внятный отказ, чем падение на `null`.
        if (! $user instanceof User) {
            throw new AuthenticationException('Инструмент доступен только сотруднику, вошедшему в платформу.');
        }

        return $user;
    }

    /**
     * Право есть? Для `shouldRegister`, где отказ — не ошибка, а «не показывать».
     */
    protected function allows(Permission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can($permission->value);
    }

    protected function allowsAny(Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($permission)) {
                return true;
            }
        }

        return false;
    }

    protected function isAdministrator(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->accessLevel()->grantsEverything();
    }

    protected function isSuperAdministrator(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->accessLevel() === AccessLevel::SuperAdmin;
    }

    /**
     * Пустить, если право есть, и записать в журнал.
     *
     * @throws AuthenticationException|AuthorizationException
     */
    protected function permit(Request $request, Permission $permission): User
    {
        $actor = $this->actor();

        Gate::forUser($actor)->authorize($permission->value);

        return $this->journal($actor, $request);
    }

    /**
     * Любое из прав — там, где разделов несколько, а инструмент один: отбор
     * внутри всё равно идёт по открытым разделам (см. KnowledgeBase).
     *
     * @throws AuthenticationException|AuthorizationException
     */
    protected function permitAny(Request $request, Permission ...$permissions): User
    {
        $actor = $this->actor();

        foreach ($permissions as $permission) {
            if ($actor->can($permission->value)) {
                return $this->journal($actor, $request);
            }
        }

        throw new AuthorizationException('Этот раздел платформы вам не открыт.');
    }

    /**
     * Администратору и выше. Правом это не выразить: `Gate::before` пропускает
     * администраторов и не умеет сказать «и никого больше» — тот же случай, что
     * и у App\Http\Middleware\EnsureAdministrator.
     *
     * @throws AuthenticationException|AuthorizationException
     */
    protected function permitAdministrator(Request $request): User
    {
        $actor = $this->actor();

        if (! $actor->accessLevel()->grantsEverything()) {
            throw new AuthorizationException('Это доступно администратору платформы.');
        }

        return $this->journal($actor, $request);
    }

    /**
     * @throws AuthenticationException|AuthorizationException
     */
    protected function permitSuperAdministrator(Request $request): User
    {
        $actor = $this->actor();

        if ($actor->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new AuthorizationException('Это доступно только суперадминистратору.');
        }

        return $this->journal($actor, $request);
    }

    /**
     * Без права: сам о себе, своё избранное, свой план обучения.
     *
     * @throws AuthenticationException
     */
    protected function permitAnyone(Request $request): User
    {
        return $this->journal($this->actor(), $request);
    }

    /**
     * Сделать изменение, обратив отказ по состоянию в ошибку инструмента.
     *
     * Действия отказывают не только по правам: «сотрудник уже уволен», «нельзя
     * понизить самого себя», «должен остаться хотя бы один суперадминистратор».
     * В HTTP это 409 с тем же текстом (App\Exceptions\ConflictException), а
     * здесь — ошибка инструмента, и текст важно донести дословно: без него
     * модель получила бы «внутреннюю ошибку» и принялась бы повторять то же
     * действие, вместо того чтобы передать человеку причину.
     *
     * Сюда же недоступная аналитика: ClickHouse стоит на сервере компании и
     * доезжает через туннель, так что «не ответил» — обыкновенное положение
     * дел, а не поломка платформы. Модель должна сказать об этом словами и
     * предложить повторить, а не отвечать «внутренняя ошибка».
     *
     * @param  callable(): (Response|ResponseFactory)  $change
     */
    protected function attempt(callable $change): Response|ResponseFactory
    {
        try {
            return $change();
        } catch (ConflictException $conflict) {
            return Response::error($conflict->getMessage());
        } catch (AnalyticsUnavailable $unavailable) {
            return Response::error('Сервер аналитики не ответил: '.$unavailable->getMessage());
        }
    }

    /**
     * Человек по номеру — с внятным отказом вместо падения на `null`.
     *
     * @throws AuthorizationException
     */
    protected function person(int $id): User
    {
        $user = User::query()->find($id);

        if (! $user instanceof User) {
            throw new AuthorizationException("Сотрудник №{$id} не найден.");
        }

        return $user;
    }

    private function journal(User $actor, Request $request): User
    {
        Log::channel('mcp')->info('Коннектор: '.$this->name(), [
            'actor_id' => $actor->getKey(),
            'actor' => $actor->name,
            'level' => $actor->accessLevel()->value,
            'arguments' => $request->all(),
        ]);

        return $actor;
    }
}
