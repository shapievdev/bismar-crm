<?php

declare(strict_types=1);

namespace Tests\Feature\Groups;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Группы сотрудников: кто их заводит, кто читает и как набирается состав.
 */
final class GroupTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Кто это видит и кто правит ---------- */

    /**
     * Название группы не тайна: без него не выбрать адресата новости тому, кто
     * её ведёт, а права на людей у него может и не быть.
     */
    public function test_the_list_is_open_to_everyone_who_signed_in(): void
    {
        Group::factory()->create(['name' => 'Наставники']);

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('groups.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Наставники');
    }

    public function test_a_guest_sees_nothing(): void
    {
        $this->getJson(route('groups.index'))->assertUnauthorized();
    }

    public function test_an_ordinary_person_cannot_create_a_group(): void
    {
        $this->actingAs($this->userWith(Permission::ManageUsers))
            ->postJson(route('groups.store'), ['name' => 'Наставники'])
            ->assertForbidden();
    }

    public function test_an_administrator_creates_a_group(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('groups.store'), [
                'name' => 'Наставники',
                'description' => 'Кто ведёт новичков',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Наставники')
            ->assertJsonPath('data.description', 'Кто ведёт новичков')
            ->assertJsonPath('data.people_count', 0);
    }

    public function test_a_duplicate_name_is_refused(): void
    {
        Group::factory()->create(['name' => 'Наставники']);

        $this->actingAs($this->administrator())
            ->postJson(route('groups.store'), ['name' => 'Наставники'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /** Своё же имя правке не мешает: переписывают описание, название оставляя. */
    public function test_a_group_keeps_its_own_name_while_being_edited(): void
    {
        $group = Group::factory()->create(['name' => 'Наставники']);

        $this->actingAs($this->administrator())
            ->putJson(route('groups.update', $group), [
                'name' => 'Наставники',
                'description' => 'Ведут новичков первый месяц',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Ведут новичков первый месяц');
    }

    public function test_an_empty_description_is_stored_as_nothing(): void
    {
        $group = Group::factory()->create(['description' => 'Прежнее']);

        $this->actingAs($this->administrator())
            ->putJson(route('groups.update', $group), ['name' => $group->name, 'description' => ''])
            ->assertOk()
            ->assertJsonPath('data.description', null);
    }

    public function test_an_administrator_deletes_a_group(): void
    {
        $group = Group::factory()->create();
        $group->members()->attach($this->learner()->id);

        $this->actingAs($this->administrator())
            ->deleteJson(route('groups.destroy', $group))
            ->assertNoContent();

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        // Состав уходит вместе с группой: строки без неё ни о чём не говорят.
        $this->assertDatabaseCount('group_members', 0);
    }

    /* ---------- Состав ---------- */

    public function test_people_are_added_in_one_go_and_removed_one_by_one(): void
    {
        $group = Group::factory()->create();
        $first = User::factory()->create(['last_name' => 'Ёлкина']);
        $second = User::factory()->create(['last_name' => 'Яковлев']);

        $this->actingAs($this->administrator())
            ->postJson(route('groups.people.store', $group), ['user_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.people_count', 2)
            // По алфавиту и с ICU: под C-сортировкой «Ёлкина» уехала бы за
            // «Яковлева» — Postgres считает её байты большими.
            ->assertJsonPath('data.people.0.id', $first->id)
            ->assertJsonPath('data.people.1.id', $second->id);

        $this->actingAs($this->administrator())
            ->deleteJson(route('groups.people.destroy', [$group, $first]))
            ->assertOk()
            ->assertJsonPath('data.people_count', 1)
            ->assertJsonPath('data.people.0.id', $second->id);
    }

    /** Уже состоящий приходит второй раз без последствий: ролей внутри нет. */
    public function test_adding_someone_twice_changes_nothing(): void
    {
        $group = Group::factory()->create();
        $person = $this->learner();

        $group->members()->attach($person->id);

        $this->actingAs($this->administrator())
            ->postJson(route('groups.people.store', $group), ['user_ids' => [$person->id]])
            ->assertOk()
            ->assertJsonPath('data.people_count', 1);

        $this->assertDatabaseCount('group_members', 1);
    }

    public function test_a_dismissed_person_is_not_counted_but_can_be_removed(): void
    {
        $group = Group::factory()->create();
        $working = $this->learner();
        $dismissed = User::factory()->dismissed()->create();

        $group->members()->attach([$working->id, $dismissed->id]);

        $this->actingAs($this->administrator())
            ->getJson(route('groups.show', $group))
            ->assertOk()
            // Группа отвечает на вопрос «кого позвать», и ушедший в ней только
            // сбивал бы счёт.
            ->assertJsonPath('data.people_count', 1)
            ->assertJsonCount(1, 'data.people');

        // Строка связи при этом на месте, и снять её можно: изъятие идёт через
        // members(), которая уволенных видит.
        $this->actingAs($this->administrator())
            ->deleteJson(route('groups.people.destroy', [$group, $dismissed]))
            ->assertOk();

        $this->assertDatabaseCount('group_members', 1);
    }

    public function test_a_dismissed_person_cannot_be_added(): void
    {
        $group = Group::factory()->create();

        $this->actingAs($this->administrator())
            ->postJson(route('groups.people.store', $group), [
                'user_ids' => [User::factory()->dismissed()->create()->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids.0');
    }

    /* ---------- Поиск ---------- */

    public function test_groups_are_searched_by_name_regardless_of_case(): void
    {
        Group::factory()->create(['name' => 'Наставники']);
        Group::factory()->create(['name' => 'Кассиры']);

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('groups.index', ['search' => 'настав']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Наставники');
    }
}
