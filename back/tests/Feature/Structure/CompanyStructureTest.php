<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Структура компании: дерево отделов, люди в них и правила, по которым его
 * можно перерисовывать.
 */
final class CompanyStructureTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Компания — единственный отдел без родителя, заведённый миграцией. */
    private function root(): Department
    {
        return Department::query()->whereNull('parent_id')->firstOrFail();
    }

    private function department(string $name, ?Department $parent = null, int $position = 0): Department
    {
        return Department::factory()->create([
            'name' => $name,
            'parent_id' => ($parent ?? $this->root())->getKey(),
            'position' => $position,
        ]);
    }

    /* ---------- Кто это видит ---------- */

    public function test_the_structure_is_open_to_everyone_who_signed_in(): void
    {
        $this->department('IT Отдел');

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('structure.index'))
            ->assertOk()
            ->assertJsonPath('data.0.is_root', true)
            ->assertJsonPath('data.0.children.0.name', 'IT Отдел');
    }

    public function test_a_guest_sees_nothing(): void
    {
        $this->getJson(route('structure.index'))->assertUnauthorized();
    }

    /* ---------- Кто её рисует ---------- */

    public function test_an_administrator_adds_a_department(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('structure.departments.store'), [
                'name' => 'Финансовый департамент',
                'parent_id' => $this->root()->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Финансовый департамент');

        $this->assertSame(1, Department::query()->where('name', 'Финансовый департамент')->count());
    }

    /**
     * Структуру рисует должность, а не отмеченное право: смотреть её дают
     * всем, а менять — двоим.
     */
    public function test_an_ordinary_employee_cannot_touch_the_structure(): void
    {
        $department = $this->department('IT Отдел');
        $employee = $this->userWith(Permission::ViewUsers, Permission::ManageUsers);

        $this->actingAs($employee)
            ->postJson(route('structure.departments.store'), [
                'name' => 'Свой отдел',
                'parent_id' => $this->root()->getKey(),
            ])
            ->assertForbidden();

        $this->actingAs($employee)
            ->deleteJson(route('structure.departments.destroy', $department))
            ->assertForbidden();

        $this->actingAs($employee)
            ->getJson(route('structure.people.candidates'))
            ->assertForbidden();
    }

    public function test_a_department_is_renamed(): void
    {
        $department = $this->department('Отдел кадров');

        $this->actingAs($this->administrator())
            ->putJson(route('structure.departments.update', $department), ['name' => 'HR департамент'])
            ->assertOk()
            ->assertJsonPath('data.name', 'HR департамент');
    }

    /* ---------- Перетаскивание ---------- */

    public function test_a_department_moves_under_another_and_takes_its_place_among_siblings(): void
    {
        $executive = $this->department('Исполнительный директор', position: 0);
        $first = $this->department('Первый', $executive, 0);
        $second = $this->department('Второй', $executive, 1);
        $moved = $this->department('Тендерный отдел', position: 1);

        $this->actingAs($this->administrator())
            ->putJson(route('structure.departments.move', $moved), [
                'parent_id' => $executive->getKey(),
                'position' => 1,
            ])
            ->assertOk();

        $this->assertSame($executive->getKey(), $moved->refresh()->parent_id);

        // Соседи пересчитаны: перенесённый встал вторым, бывший второй — третьим.
        $this->assertSame(0, $first->refresh()->position);
        $this->assertSame(1, $moved->position);
        $this->assertSame(2, $second->refresh()->position);
    }

    public function test_a_department_cannot_be_moved_under_its_own_branch(): void
    {
        $parent = $this->department('Коммерческий департамент');
        $child = $this->department('Отдел продаж', $parent);
        $grandchild = $this->department('Группа опта', $child);

        $this->actingAs($this->administrator())
            ->putJson(route('structure.departments.move', $parent), [
                'parent_id' => $grandchild->getKey(),
                'position' => 0,
            ])
            ->assertStatus(409);

        // Остался там, где стоял, — под компанией.
        $this->assertSame($this->root()->getKey(), $parent->refresh()->parent_id);
    }

    public function test_the_company_itself_stays_at_the_top(): void
    {
        $department = $this->department('IT Отдел');

        $this->actingAs($this->administrator())
            ->putJson(route('structure.departments.move', $this->root()), [
                'parent_id' => $department->getKey(),
                'position' => 0,
            ])
            ->assertStatus(409);

        $this->actingAs($this->administrator())
            ->deleteJson(route('structure.departments.destroy', $this->root()))
            ->assertStatus(409);

        $this->assertNull($this->root()->parent_id);
    }

    /**
     * Расформированное направление обычно означает, что его отделы
     * переподчинили выше, а не что их распустили.
     */
    public function test_deleting_a_department_lifts_its_children_to_the_grandparent(): void
    {
        $parent = $this->department('Операционный департамент');
        $child = $this->department('Логистика', $parent);

        $this->actingAs($this->administrator())
            ->deleteJson(route('structure.departments.destroy', $parent))
            ->assertNoContent();

        $this->assertSame($this->root()->getKey(), $child->refresh()->parent_id);
        $this->assertDatabaseMissing('departments', ['id' => $parent->getKey()]);
    }

    /* ---------- Люди ---------- */

    public function test_people_are_added_with_a_role_and_counted_on_the_card(): void
    {
        $department = $this->department('IT Отдел');
        $head = User::factory()->create(['job_title' => 'Руководитель IT']);
        $deputy = User::factory()->create();
        [$first, $second] = User::factory()->count(2)->create()->all();

        $administrator = $this->administrator();

        foreach ([[DepartmentRole::Head, [$head]], [DepartmentRole::Deputy, [$deputy]], [DepartmentRole::Member, [$first, $second]]] as [$role, $people]) {
            $this->actingAs($administrator)
                ->postJson(route('structure.people.store', $department), [
                    'user_ids' => array_map(static fn (User $person): int => $person->id, $people),
                    'role' => $role->value,
                ])
                ->assertOk();
        }

        $card = $this->actingAs($administrator)
            ->getJson(route('structure.index'))
            ->assertOk()
            ->json('data.0.children.0');

        $this->assertSame('Руководитель IT', $card['heads'][0]['job_title']);
        $this->assertSame($deputy->id, $card['deputies'][0]['id']);

        // Лица подчинённых — на карточке, чтобы она показывала людей, а не
        // одно число; весь состав отдаётся отдельно, когда открывают панель.
        $this->assertCount(2, $card['members']);

        // «Подчинённые» — прямые сотрудники, счётчик у имени — весь куст.
        $this->assertSame(2, $card['members_count']);
        $this->assertSame(4, $card['people_total']);
        $this->assertSame(0, $card['children_count']);
    }

    /**
     * Число у имени руководителя — это весь куст, и человек, числящийся в
     * двух его отделах, считается один раз.
     */
    public function test_the_branch_total_counts_a_person_once(): void
    {
        $parent = $this->department('Гипермаркет');
        $child = $this->department('Касса', $parent);
        $person = User::factory()->create();
        $administrator = $this->administrator();

        foreach ([$parent, $child] as $department) {
            $this->actingAs($administrator)
                ->postJson(route('structure.people.store', $department), [
                    'user_ids' => [$person->id],
                    'role' => DepartmentRole::Member->value,
                ])
                ->assertOk();
        }

        $card = $this->actingAs($administrator)->getJson(route('structure.index'))->json('data.0.children.0');

        $this->assertSame(1, $card['people_total']);
        $this->assertSame(1, $card['children_count']);
    }

    public function test_a_role_is_changed_and_a_person_is_removed(): void
    {
        $department = $this->department('Служба безопасности');
        $person = User::factory()->create();
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->postJson(route('structure.people.store', $department), [
                'user_ids' => [$person->id],
                'role' => DepartmentRole::Member->value,
            ])
            ->assertOk();

        $this->actingAs($administrator)
            ->putJson(route('structure.people.update', [$department, $person]), [
                'role' => DepartmentRole::Head->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.0.role', DepartmentRole::Head->value);

        $this->actingAs($administrator)
            ->deleteJson(route('structure.people.destroy', [$department, $person]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Структура о том, кто работает сейчас: уволенный уходит из неё сам, а
     * строка связи остаётся — вернувшийся встаёт на прежнее место.
     */
    public function test_a_dismissed_person_leaves_the_structure_but_not_its_records(): void
    {
        $department = $this->department('Тендерный отдел');
        $person = User::factory()->create();
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->postJson(route('structure.people.store', $department), [
                'user_ids' => [$person->id],
                'role' => DepartmentRole::Member->value,
            ])
            ->assertOk();

        $this->actingAs($administrator)->postJson(route('users.dismiss', $person))->assertOk();

        $this->actingAs($administrator)
            ->getJson(route('structure.people.index', $department))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('department_members', [
            'department_id' => $department->getKey(),
            'user_id' => $person->getKey(),
        ]);

        $this->actingAs($administrator)->deleteJson(route('users.reinstate', $person))->assertOk();

        $this->actingAs($administrator)
            ->getJson(route('structure.people.index', $department))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Перетаскивание человека с карточки на карточку: в новом отделе он есть,
     * в прежнем его больше нет, и роль переезжает вместе с ним.
     */
    public function test_a_person_is_dragged_from_one_department_to_another(): void
    {
        $from = $this->department('Операционный департамент');
        $to = $this->department('Гипермаркет');
        $person = User::factory()->create();
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->postJson(route('structure.people.store', $from), [
                'user_ids' => [$person->id],
                'role' => DepartmentRole::Deputy->value,
            ])
            ->assertOk();

        $this->actingAs($administrator)
            ->postJson(route('structure.people.store', $to), [
                'user_ids' => [$person->id],
                'role' => DepartmentRole::Deputy->value,
                'from_department_id' => $from->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.role', DepartmentRole::Deputy->value);

        $this->assertSame(0, $from->people()->count());
        $this->assertSame(1, $to->people()->count());
    }

    /**
     * Без указания, откуда его забрать, человек просто прибавляется: числиться
     * в двух отделах разом можно, и перенос от добавления отличает только это.
     */
    public function test_adding_without_a_source_leaves_the_other_department_alone(): void
    {
        $first = $this->department('Коммерческий департамент');
        $second = $this->department('Тендерный отдел');
        $person = User::factory()->create();
        $administrator = $this->administrator();

        foreach ([$first, $second] as $department) {
            $this->actingAs($administrator)
                ->postJson(route('structure.people.store', $department), [
                    'user_ids' => [$person->id],
                    'role' => DepartmentRole::Member->value,
                ])
                ->assertOk();
        }

        $this->assertSame(1, $first->people()->count());
        $this->assertSame(1, $second->people()->count());
    }

    public function test_a_dismissed_person_is_not_offered_and_not_added(): void
    {
        $department = $this->department('IT Отдел');
        $dismissed = User::factory()->dismissed()->create(['first_name' => 'Пров']);

        $this->actingAs($this->administrator())
            ->getJson(route('structure.people.candidates', ['search' => 'Пров']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->administrator())
            ->postJson(route('structure.people.store', $department), [
                'user_ids' => [$dismissed->id],
                'role' => DepartmentRole::Member->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids.0');
    }
}
