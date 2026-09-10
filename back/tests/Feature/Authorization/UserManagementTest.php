<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * An administrator staffing the system: adding colleagues, correcting their
 * records and choosing what they may do.
 */
final class UserManagementTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_administrator_can_create_a_user(): void
    {
        $response = $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'middle_name' => 'Августовна',
                'email' => 'ada@bismar.test',
                'phone' => '+79990009977',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Лавлейс Ада Августовна')
            ->assertJsonPath('data.own_permissions', [])
            ->assertJsonMissingPath('data.password');

        $created = User::firstWhere('email', 'ada@bismar.test');

        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $created->password));
        $this->assertSame($response->json('data.id'), $created->id);
    }

    /**
     * Карточку человека открывает тот же, кто читает список: это одно и то же
     * чтение, только об одном.
     */
    public function test_a_profile_is_opened_by_whoever_may_read_the_staff_list(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977', 'job_title' => 'Кладовщик']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.show', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey())
            ->assertJsonPath('data.phone', '+79990009977')
            ->assertJsonPath('data.job_title', 'Кладовщик');
    }

    public function test_a_profile_is_closed_to_someone_without_that_right(): void
    {
        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('users.show', User::factory()->create()))
            ->assertForbidden();
    }

    /* ---------- Поиск по списку ---------- */

    /**
     * Строчными и по-русски: базы собраны с C-сортировкой, где ILIKE
     * складывает только латиницу, — без ICU «ёлкин» не нашёл бы Ёлкину.
     */
    public function test_the_staff_list_is_searched_by_name_regardless_of_case(): void
    {
        $wanted = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Мария']);
        User::factory()->create(['last_name' => 'Яковлев', 'first_name' => 'Пётр']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.index', ['search' => 'ёлкин']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->getKey());
    }

    public function test_the_staff_list_is_searched_by_address(): void
    {
        $wanted = User::factory()->create(['email' => 'sklad@bismar.test']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.index', ['search' => 'SKLAD@']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->getKey());
    }

    /**
     * Отбор идёт в базу, а не по загруженной странице: искомый стоит за концом
     * первой страницы и по ней найден бы не был.
     */
    public function test_the_search_reaches_past_the_first_page(): void
    {
        User::factory()->count(30)->create(['last_name' => 'Яковлев']);
        $wanted = User::factory()->create(['last_name' => 'Ёлкина']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.index', ['search' => 'Ёлкина']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->getKey());
    }

    /**
     * Где человек в структуре компании — столбец списка, а не только карточки.
     */
    public function test_the_staff_list_shows_which_departments_a_person_is_in(): void
    {
        $department = Department::factory()->create([
            'name' => 'Отдел продаж',
            'parent_id' => Department::query()->whereNull('parent_id')->value('id'),
        ]);

        $member = User::factory()->create(['last_name' => 'Ёлкина']);
        $member->departments()->attach($department, ['role' => DepartmentRole::Head->value]);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.index', ['search' => 'Ёлкина']))
            ->assertOk()
            ->assertJsonPath('data.0.departments.0.name', 'Отдел продаж')
            ->assertJsonPath('data.0.departments.0.role', DepartmentRole::Head->value)
            ->assertJsonPath('data.0.departments.0.role_label', 'Руководитель');
    }

    /** Не состоящий ни в одном отделе приходит с пустым списком, а не без него. */
    public function test_someone_outside_the_structure_comes_with_no_departments(): void
    {
        User::factory()->create(['last_name' => 'Ёлкина']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('users.index', ['search' => 'Ёлкина']))
            ->assertOk()
            ->assertJsonPath('data.0.departments', []);
    }

    public function test_a_duplicate_address_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@bismar.test']);

        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'taken@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_user_without_the_manage_permission_cannot_create_anyone(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertForbidden();
    }

    public function test_an_administrator_can_correct_a_users_record(): void
    {
        $user = User::factory()->create(['last_name' => 'Старая', 'middle_name' => 'Отчество']);

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => $user->phone,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Лавлейс Ада');

        // An omitted patronymic clears it — the form always sends the whole record.
        $this->assertNull($user->refresh()->middle_name);
    }

    public function test_a_password_is_left_alone_unless_a_new_one_is_sent(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => $user->phone,
            ])
            ->assertOk();

        $this->assertSame($before, $user->refresh()->password);
    }

    /**
     * Номер приходит набранным как угодно, а ложится в базу одним видом.
     *
     * Проверяется правкой одного и того же человека, а не четырьмя новыми:
     * номер уникален, и четыре записи с одним номером — как раз то, чего эта
     * нормализация и не даёт случиться.
     */
    public function test_a_phone_number_is_stored_in_one_shape(): void
    {
        $user = User::factory()->create();

        foreach (['8 (999) 000-99-77', '+7 999 000 99 77', '9990009977', '+79990009977'] as $typed) {
            $this->actingAs($this->administrator())
                ->putJson(route('users.update', $user), [
                    'last_name' => 'Лавлейс',
                    'first_name' => 'Ада',
                    'email' => 'ada@bismar.test',
                    'phone' => $typed,
                    'job_title' => 'Программист',
                ])
                ->assertOk()
                ->assertJsonPath('data.phone', '+79990009977')
                ->assertJsonPath('data.job_title', 'Программист');
        }
    }

    /**
     * Один номер на двоих — это двое, входящих в одну учётную запись.
     */
    public function test_a_phone_that_belongs_to_someone_else_is_refused(): void
    {
        User::factory()->create(['phone' => '+79990009977']);

        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '8 (999) 000-99-77',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_number_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '12-34',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    /**
     * Должность необязательна: без неё сотрудник заводится и живёт. Телефон —
     * нет: с него входят, и запись без номера была бы учётной записью, в
     * которую нельзя попасть.
     */
    public function test_a_colleague_is_created_without_a_job_title(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '+79990009977',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job_title', null);
    }

    public function test_a_colleague_cannot_be_created_without_a_phone(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_phone_and_a_job_title_are_corrected(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977', 'job_title' => 'Стажёр']);

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '8 (999) 111-22-33',
                'job_title' => 'Ведущий разработчик',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+79991112233')
            ->assertJsonPath('data.job_title', 'Ведущий разработчик');

        // Пустая должность — это «убрать», а не «оставить как было»: форма
        // присылает запись целиком.
        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '+79991112233',
                'job_title' => null,
            ])
            ->assertOk();

        $this->assertNull($user->refresh()->job_title);
    }

    /**
     * Телефон стереть нельзя ни пустой строкой, ни молчанием: это заперло бы
     * человека снаружи собственной учётной записи.
     */
    public function test_a_phone_cannot_be_wiped(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977']);

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame('+79990009977', $user->refresh()->phone);
    }

    public function test_an_administrator_can_reset_a_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'phone' => $user->phone,
                'password' => 'a-brand-new-passphrase',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->refresh()->password));
    }
}
