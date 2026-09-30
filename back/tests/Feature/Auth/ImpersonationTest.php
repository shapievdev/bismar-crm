<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Impersonation;
use App\Models\User;
use App\Support\Auth\Impersonation as ImpersonationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Работа от чужого имени (решение пользователя 2026-09-30).
 *
 * Суперадминистратор входит под сотрудником, не выходя из себя, и возвращается
 * в одно нажатие: свой вход никуда не девается. Нужно это ради вопроса «что
 * видит этот человек» — права, план обучения и закрытые материалы складываются
 * так, что со стороны их не пересчитать.
 */
final class ImpersonationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_superadmin_works_as_an_employee_and_comes_back(): void
    {
        $actor = $this->superAdministrator();
        $employee = $this->learner();

        $this->signIn($actor);

        $this->postJson(route('auth.impersonate', $employee))
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.impersonated_by', $actor->name);

        // Сессия отдаёт сотрудника, а память о вошедшем остаётся при ней.
        $this->assertAuthenticatedAs($employee, 'web');
        $this->assertSame($actor->id, session(ImpersonationState::ACTOR));

        // Журнал открыт: по нему потом разбирают, кто что сделал.
        $record = Impersonation::query()->sole();

        $this->assertSame($actor->id, $record->impersonator_id);
        $this->assertSame($employee->id, $record->user_id);
        $this->assertNull($record->ended_at);

        $this->deleteJson(route('auth.impersonate.stop'))
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id)
            // Вернулись к себе — полосы «вы под кем-то» больше нет.
            ->assertJsonMissingPath('data.impersonated_by');

        $this->assertAuthenticatedAs($actor, 'web');
        $this->assertNull(session(ImpersonationState::ACTOR));
        $this->assertNotNull($record->refresh()->ended_at);
    }

    /**
     * Под чужим именем приложение ведёт себя как для сотрудника.
     *
     * Это и есть весь смысл: суперадминистратор видит то, что видит человек, —
     * своих прав он в чужой учётной записи не сохраняет.
     */
    public function test_the_session_answers_as_the_employee(): void
    {
        $employee = $this->learner();

        $this->signIn($this->superAdministrator());

        $this->postJson(route('auth.impersonate', $employee))->assertOk();

        $this->startNextRequest();

        // Следующий запрос отвечает уже сотрудником — и его уровнем доступа:
        // своих прав суперадминистратор в чужой учётной записи не сохраняет.
        $this->getJson(route('auth.user'))
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.level', 'user');
    }

    /* ---------- Кому и под кем нельзя ---------- */

    public function test_an_administrator_cannot_do_it(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('auth.impersonate', $this->learner()))
            ->assertStatus(409);

        $this->assertDatabaseCount('impersonations', 0);
    }

    public function test_a_plain_user_cannot_do_it(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('auth.impersonate', $this->learner()))
            ->assertStatus(409);
    }

    public function test_nobody_works_as_another_superadmin(): void
    {
        $this->actingAs($this->superAdministrator())
            ->postJson(route('auth.impersonate', $this->superAdministrator()))
            ->assertStatus(409);

        $this->assertDatabaseCount('impersonations', 0);
    }

    /** Уволенному платформа закрыта — под ним первый же запрос ответил бы 401. */
    public function test_nobody_works_as_a_dismissed_employee(): void
    {
        $dismissed = $this->learner();
        $dismissed->forceFill(['dismissed_at' => now()])->save();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('auth.impersonate', $dismissed))
            ->assertStatus(409);
    }

    public function test_the_actor_does_not_work_as_themselves(): void
    {
        $actor = $this->superAdministrator();

        $this->actingAs($actor)
            ->postJson(route('auth.impersonate', $actor))
            ->assertStatus(409);
    }

    /**
     * Из чужого имени в третье не переходят.
     *
     * Вернуться можно только туда, откуда пришёл, и цепочка возвратов
     * превратила бы «вернуться к себе» в «вернуться неизвестно куда».
     */
    public function test_there_is_no_nesting(): void
    {
        $actor = $this->superAdministrator();
        $first = $this->learner();
        $second = $this->learner();

        $this->signIn($actor);

        $this->postJson(route('auth.impersonate', $first))->assertOk();
        $this->postJson(route('auth.impersonate', $second))->assertStatus(409);

        // Остались под первым, и журнал не раздвоился.
        $this->assertAuthenticatedAs($first, 'web');
        $this->assertDatabaseCount('impersonations', 1);
    }

    public function test_coming_back_from_nowhere_is_refused(): void
    {
        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('auth.impersonate.stop'))
            ->assertStatus(409);
    }

    /**
     * Выход из приложения закрывает окно.
     *
     * Незакрытое означало бы, что суперадминистратор сидит под сотрудником
     * вечно, — а по окнам потом разбирают, кто что сделал.
     */
    public function test_logging_out_closes_the_window(): void
    {
        $actor = $this->superAdministrator();

        $this->signIn($actor);

        $this->postJson(route('auth.impersonate', $this->learner()))->assertOk();
        $this->postJson(route('auth.logout'))->assertNoContent();

        $this->assertNotNull(Impersonation::query()->sole()->ended_at);
    }

    /** Чужой номер в адресе — чужая учётная запись, а не своя: проверяется всё то же. */
    public function test_an_unknown_user_is_not_found(): void
    {
        $this->actingAs($this->superAdministrator())
            ->postJson('/api/auth/impersonate/999999')
            ->assertNotFound();
    }

    /**
     * Настоящий вход, а не подстановка пользователя.
     *
     * Здесь проверяется сама сессия: под чужим именем приложение ведёт себя как
     * для сотрудника ровно потому, что в сессии лежит он. С `actingAs` этого не
     * увидеть — она подставляет человека мимо сессии, и следующий запрос
     * отвечал бы прежним.
     */
    private function signIn(User $user): void
    {
        $this->postJson(route('auth.login'), [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertOk();

        $this->startNextRequest();
    }

    /**
     * Забыть разобранных guard'ов — как это делает новый запрос в браузере.
     *
     * В тестах приложение живёт между запросами, и guard `sanctum` держит уже
     * найденного человека при себе. Без этого второй запрос отвечал бы тем, кого
     * нашли в первом, — и подмена учётной записи выглядела бы несработавшей,
     * хотя в сессии лежит уже другой.
     */
    private function startNextRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
