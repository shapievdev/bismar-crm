<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\Staff\AssignStaffTags;
use App\Mcp\Tools\Staff\CreateStaff;
use App\Mcp\Tools\Staff\DeleteStaff;
use App\Mcp\Tools\Staff\DismissStaff;
use App\Mcp\Tools\Staff\ReinstateStaff;
use App\Mcp\Tools\Staff\RestoreStaff;
use App\Mcp\Tools\Staff\SearchStaff;
use App\Mcp\Tools\Staff\SetDismissalDate;
use App\Mcp\Tools\Staff\SetStaffAccess;
use App\Mcp\Tools\Staff\ShowStaff;
use App\Mcp\Tools\Staff\UpdateStaff;
use App\Models\StaffTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Инструменты раздела «Сотрудники».
 *
 * Проверяется то же, что у HTTP-API, и ровно потому, что это одни и те же
 * правила: инструмент не должен давать коннектору того, чего экран не даёт.
 * Поэтому у каждого — удачный вызов и отказ: кому нельзя, тому нельзя и здесь.
 */
final class StaffToolsTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    /* ---------- Что человеку вообще видно ---------- */

    /**
     * Продавец не видит инструментов правки людей.
     *
     * Это первая и самая дешёвая защита: список инструментов отфильтрован по
     * правам, и модели нечем даже попробовать.
     */
    public function test_a_seller_is_not_offered_the_tools_they_may_not_use(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewCourses))
            ->tools()
            ->assertNotRegistered([
                SearchStaff::class,
                ShowStaff::class,
                CreateStaff::class,
                UpdateStaff::class,
                SetStaffAccess::class,
                DismissStaff::class,
                DeleteStaff::class,
                AssignStaffTags::class,
            ]);
    }

    /** Кадровик, которому дали смотреть людей, видит только чтение. */
    public function test_reading_people_does_not_bring_the_tools_that_change_them(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewUsers))
            ->tools()
            ->assertRegistered([SearchStaff::class, ShowStaff::class])
            ->assertNotRegistered([CreateStaff::class, UpdateStaff::class, DismissStaff::class]);
    }

    public function test_an_administrator_is_offered_everything_but_deletion(): void
    {
        Bismar::actingAs($this->administrator())
            ->tools()
            ->assertRegistered([
                SearchStaff::class,
                CreateStaff::class,
                UpdateStaff::class,
                SetStaffAccess::class,
                DismissStaff::class,
                SetDismissalDate::class,
                ReinstateStaff::class,
                AssignStaffTags::class,
            ])
            // Удаляет и возвращает удалённое один суперадминистратор.
            ->assertNotRegistered([DeleteStaff::class, RestoreStaff::class]);
    }

    public function test_a_superadministrator_is_offered_deletion(): void
    {
        Bismar::actingAs($this->superAdministrator())
            ->tools()
            ->assertRegistered([DeleteStaff::class, RestoreStaff::class]);
    }

    /* ---------- Поиск и карточка ---------- */

    public function test_the_search_finds_people_by_name_and_job_title(): void
    {
        User::factory()->create(['last_name' => 'Курабанов', 'first_name' => 'Давлет', 'job_title' => 'Продавец']);
        User::factory()->create(['last_name' => 'Яковлев', 'first_name' => 'Пётр', 'job_title' => 'Кладовщик']);

        Bismar::actingAs($this->userWith(Permission::ViewUsers))
            ->tool(SearchStaff::class, ['search' => 'Кураб'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('total', 1)
                ->where('people', fn (Collection $people): bool => $people->first()['name'] === 'Курабанов Давлет')
                // Должности едут со страницей — иначе отбор по ним был бы угадыванием.
                ->where('job_titles', fn (Collection $titles): bool => $titles->contains('Продавец')
                    && $titles->contains('Кладовщик'))
                ->etc());
    }

    public function test_the_search_filters_by_job_title_exactly(): void
    {
        User::factory()->create(['job_title' => 'Мастер']);
        User::factory()->create(['job_title' => 'Мастер-наставник']);

        Bismar::actingAs($this->userWith(Permission::ViewUsers))
            ->tool(SearchStaff::class, ['job_title' => 'Мастер'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 1)->etc());
    }

    /** Удалённых показывают одному суперадминистратору. */
    public function test_only_a_superadministrator_sees_deleted_records(): void
    {
        Bismar::actingAs($this->administrator())
            ->tool(SearchStaff::class, ['deleted' => true])
            ->assertHasErrors(['Удалённые записи видит только суперадминистратор.']);
    }

    public function test_the_card_tells_what_the_person_may_do(): void
    {
        $person = $this->userWith(Permission::ViewAnalytics);

        Bismar::actingAs($this->userWith(Permission::ViewUsers))
            ->tool(ShowStaff::class, ['employee_id' => $person->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('id', $person->getKey())
                ->where('access_level', 'user')
                ->where('own_permissions', ['analytics.view'])
                ->etc());
    }

    public function test_an_unknown_number_is_refused_rather_than_crashing(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewUsers))
            ->tool(ShowStaff::class, ['employee_id' => 9999])
            ->assertHasErrors(['Сотрудник №9999 не найден.']);
    }

    /* ---------- Заведение и правка ---------- */

    public function test_an_administrator_adds_a_colleague(): void
    {
        Bismar::actingAs($this->administrator())
            ->tool(CreateStaff::class, [
                'last_name' => 'Шапиев',
                'first_name' => 'Абдулла',
                'phone' => '8 (999) 123-45-67',
                'job_title' => 'Продавец',
                'password' => 'secret-enough',
            ])
            ->assertOk()
            ->assertHasNoErrors();

        // Номер приводится к хранимому виду: «8 (999)…» и «+7 999…» — один номер.
        $this->assertDatabaseHas('users', [
            'last_name' => 'Шапиев',
            'phone' => '+79991234567',
        ]);

        // Прав новичку не достаётся: доступ ставят вторым решением.
        $this->assertSame([], User::query()
            ->where('last_name', 'Шапиев')
            ->sole()
            ->permissions
            ->pluck('name')
            ->all());
    }

    public function test_a_taken_phone_number_is_refused_in_words(): void
    {
        User::factory()->create(['phone' => '+79991234567']);

        Bismar::actingAs($this->administrator())
            ->tool(CreateStaff::class, [
                'last_name' => 'Шапиев',
                'first_name' => 'Абдулла',
                'phone' => '+79991234567',
                'password' => 'secret-enough',
            ])
            ->assertHasErrors();
    }

    /**
     * Присланное одно поле не стирает остальные.
     *
     * Карточка правится целиком — это свойство формы, — и модель, присылающая
     * одно поле, стёрла бы всё прочее. Непришедшее поэтому берётся из нынешней
     * записи.
     */
    public function test_editing_one_field_leaves_the_rest_alone(): void
    {
        $person = User::factory()->create([
            'last_name' => 'Курабанов',
            'first_name' => 'Давлет',
            'middle_name' => 'Избуллаевич',
            'job_title' => 'Продавец',
            'email' => 'kurabanov@bismar.pro',
        ]);

        Bismar::actingAs($this->administrator())
            ->tool(UpdateStaff::class, [
                'employee_id' => $person->getKey(),
                'job_title' => 'Старший продавец',
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $fresh = $person->fresh();

        $this->assertSame('Старший продавец', $fresh?->job_title);
        $this->assertSame('Избуллаевич', $fresh?->middle_name);
        $this->assertSame('kurabanov@bismar.pro', $fresh?->email);
    }

    /** А пустая строка — стирает: ею и просят убрать необязательное. */
    public function test_an_empty_string_clears_an_optional_field(): void
    {
        $person = User::factory()->create(['middle_name' => 'Избуллаевич']);

        Bismar::actingAs($this->administrator())
            ->tool(UpdateStaff::class, [
                'employee_id' => $person->getKey(),
                'middle_name' => '',
            ])
            ->assertOk();

        $this->assertNull($person->fresh()?->middle_name);
    }

    /* ---------- Доступ ---------- */

    public function test_an_administrator_grants_rights(): void
    {
        $person = User::factory()->create();

        Bismar::actingAs($this->administrator())
            ->tool(SetStaffAccess::class, [
                'employee_id' => $person->getKey(),
                'access_level' => 'user',
                'permissions' => ['analytics.view', 'users.view'],
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['analytics.view', 'users.view'],
            $person->fresh()?->permissions->pluck('name')->all(),
        );
    }

    /**
     * Правила лестницы — те же, что у экрана, и отказ доезжает словами.
     *
     * Это главное, ради чего отказы по состоянию переводятся в ошибку
     * инструмента: «внутренняя ошибка» заставила бы модель повторять попытку,
     * а причина — передать её человеку.
     */
    public function test_an_administrator_may_not_appoint_a_superadministrator(): void
    {
        $person = User::factory()->create();

        Bismar::actingAs($this->administrator())
            ->tool(SetStaffAccess::class, [
                'employee_id' => $person->getKey(),
                'access_level' => 'super-admin',
                'permissions' => [],
            ])
            ->assertHasErrors(['Назначать и снимать суперадминистраторов может только суперадминистратор.']);

        $this->assertSame(AccessLevel::User, $person->fresh()?->accessLevel());
    }

    /* ---------- Увольнение ---------- */

    public function test_an_administrator_dismisses_a_colleague_on_a_given_day(): void
    {
        $person = User::factory()->create(['hired_at' => '2025-01-10']);

        Bismar::actingAs($this->administrator())
            ->tool(DismissStaff::class, [
                'employee_id' => $person->getKey(),
                'reason' => 'own',
                'dismissed_at' => '2026-09-15',
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $dismissed = $person->fresh();

        $this->assertSame('2026-09-15', $dismissed?->dismissed_at?->toDateString());
        // Конец дня: в день увольнения человек ещё числится в штате.
        $this->assertSame('23:59:59', $dismissed?->dismissed_at?->format('H:i:s'));
        $this->assertSame('own', $dismissed?->dismissal_reason?->value);
    }

    public function test_nobody_dismisses_themselves_through_the_connector(): void
    {
        $administrator = $this->administrator();

        Bismar::actingAs($administrator)
            ->tool(DismissStaff::class, ['employee_id' => $administrator->getKey()])
            ->assertHasErrors(['Нельзя уволить самого себя.']);

        $this->assertNull($administrator->fresh()?->dismissed_at);
    }

    public function test_the_day_of_dismissal_is_corrected(): void
    {
        $person = User::factory()->dismissed()->create(['hired_at' => '2026-01-10']);

        Bismar::actingAs($this->administrator())
            ->tool(SetDismissalDate::class, [
                'employee_id' => $person->getKey(),
                'dismissed_at' => '2026-09-15',
            ])
            ->assertOk();

        $this->assertSame('2026-09-15', $person->fresh()?->dismissed_at?->toDateString());
    }

    public function test_the_day_of_dismissal_is_not_before_the_day_of_hire(): void
    {
        $person = User::factory()->dismissed()->create(['hired_at' => '2026-03-01']);

        Bismar::actingAs($this->administrator())
            ->tool(SetDismissalDate::class, [
                'employee_id' => $person->getKey(),
                'dismissed_at' => '2026-02-28',
            ])
            ->assertHasErrors();
    }

    public function test_a_working_person_has_no_day_of_dismissal_to_correct(): void
    {
        $person = User::factory()->create();

        Bismar::actingAs($this->administrator())
            ->tool(SetDismissalDate::class, [
                'employee_id' => $person->getKey(),
                'dismissed_at' => '2026-09-15',
            ])
            ->assertHasErrors(['Сотрудник работает — даты увольнения у него нет.']);
    }

    public function test_a_dismissed_colleague_is_brought_back(): void
    {
        $person = User::factory()->dismissed()->create();

        Bismar::actingAs($this->administrator())
            ->tool(ReinstateStaff::class, ['employee_id' => $person->getKey()])
            ->assertOk();

        $this->assertNull($person->fresh()?->dismissed_at);
    }

    /* ---------- Удаление ---------- */

    public function test_only_a_dismissed_record_is_deleted(): void
    {
        $person = User::factory()->create();

        Bismar::actingAs($this->superAdministrator())
            ->tool(DeleteStaff::class, ['employee_id' => $person->getKey()])
            ->assertHasErrors(['Удалить можно только уволенного сотрудника — сначала увольте его.']);

        $this->assertNotSoftDeleted($person);
    }

    public function test_a_superadministrator_deletes_and_restores_a_record(): void
    {
        $person = User::factory()->dismissed()->create();

        Bismar::actingAs($this->superAdministrator())
            ->tool(DeleteStaff::class, ['employee_id' => $person->getKey()])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertSoftDeleted($person);

        Bismar::actingAs($this->superAdministrator())
            ->tool(RestoreStaff::class, ['employee_id' => $person->getKey()])
            ->assertOk();

        $this->assertNotSoftDeleted($person);
        // Возвращается уволенной — какой её и удаляли.
        $this->assertNotNull($person->fresh()?->dismissed_at);
    }

    /* ---------- Теги ---------- */

    public function test_the_keeper_of_tags_hangs_them_on_a_person(): void
    {
        $person = User::factory()->create();
        $reserve = StaffTag::query()->create(['name' => 'Кадровый резерв']);

        Bismar::actingAs($this->userWith(Permission::ManageStaffTags))
            ->tool(AssignStaffTags::class, [
                'employee_id' => $person->getKey(),
                'tags' => [$reserve->getKey()],
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertSame(['Кадровый резерв'], $person->fresh()?->staffTags->pluck('name')->all());
    }

    /** Право на людей тегов не раздаёт: это разные работы. */
    public function test_managing_people_does_not_grant_the_tags(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageUsers))
            ->tools()
            ->assertNotRegistered(AssignStaffTags::class);
    }
}
