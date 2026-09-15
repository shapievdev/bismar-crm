<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\StaffTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Ручные теги: справочник кадровика и ярлыки на людях.
 *
 * Проверяется главным образом граница: заводить теги — не то же самое, что
 * распоряжаться учётными записями, и человек с правом на теги не должен
 * получить заодно власти над доступом. И наоборот: тот, кто правит людей, не
 * заводит ярлыки, пока ему этого не дали.
 */
final class StaffTagTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Справочник читает всякий, кто видит людей: иначе теги — набор номеров. */
    public function test_the_dictionary_is_readable_by_anyone_who_sees_people(): void
    {
        StaffTag::query()->create(['name' => 'Кадровый резерв']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->getJson(route('staff-tags.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Кадровый резерв')
            ->assertJsonPath('data.0.people', 0);
    }

    /** Заводит теги тот, кому это доверено, — и только он. */
    public function test_only_the_tag_keeper_may_add_one(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers, Permission::ManageUsers))
            ->postJson(route('staff-tags.store'), ['name' => 'Испытательный продлён'])
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ViewUsers, Permission::ManageStaffTags))
            ->postJson(route('staff-tags.store'), ['name' => 'Испытательный продлён'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Испытательный продлён');
    }

    /** Одно название — один тег: два «резерва» в фильтре не различить. */
    public function test_a_name_is_taken_only_once(): void
    {
        StaffTag::query()->create(['name' => 'Кадровый резерв']);

        $this->actingAs($this->userWith(Permission::ManageStaffTags))
            ->postJson(route('staff-tags.store'), ['name' => 'Кадровый резерв'])
            ->assertStatus(422);
    }

    /** Тег вешается на человека — и в карточке его видно. */
    public function test_a_tag_lands_on_a_person(): void
    {
        $tag = StaffTag::query()->create(['name' => 'Кадровый резерв']);
        $person = User::factory()->create();
        $keeper = $this->userWith(Permission::ViewUsers, Permission::ManageStaffTags);

        $this->actingAs($keeper)
            ->putJson(route('users.tags.update', $person), ['tags' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data.tags.0.name', 'Кадровый резерв');

        // Кто повесил — записано: через полгода «почему он в резерве»
        // спрашивают у того, кто это решил.
        $this->assertSame(
            $keeper->id,
            (int) $person->staffTags()->first()?->pivot->assigned_by_id,
        );

        $this->actingAs($keeper)
            ->getJson(route('users.show', $person))
            ->assertOk()
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    /** Пустой список снимает всё: что прислали, то на человеке и останется. */
    public function test_an_empty_list_clears_the_tags(): void
    {
        $tag = StaffTag::query()->create(['name' => 'Кадровый резерв']);
        $person = User::factory()->create();
        $person->staffTags()->attach($tag);

        $this->actingAs($this->userWith(Permission::ViewUsers, Permission::ManageStaffTags))
            ->putJson(route('users.tags.update', $person), ['tags' => []])
            ->assertOk()
            ->assertJsonPath('data.tags', []);
    }

    /**
     * Удаление тега снимает его со всех.
     *
     * Тег — ярлык, а не событие: удалённый «резерв» не должен висеть на людях
     * невидимой пометкой, до которой не добраться ни списком, ни фильтром.
     */
    public function test_deleting_a_tag_takes_it_off_everyone(): void
    {
        $tag = StaffTag::query()->create(['name' => 'Кадровый резерв']);
        $person = User::factory()->create();
        $person->staffTags()->attach($tag);

        $this->actingAs($this->userWith(Permission::ManageStaffTags))
            ->deleteJson(route('staff-tags.destroy', $tag))
            ->assertNoContent();

        $this->assertCount(0, $person->staffTags()->get());
    }

    /** Уволенного тегировать можно: ярлык про человека, а не про его положение. */
    public function test_a_dismissed_person_can_still_be_tagged(): void
    {
        $tag = StaffTag::query()->create(['name' => 'Ушёл из резерва']);
        $person = User::factory()->create(['dismissed_at' => now()->subMonth()]);

        $this->actingAs($this->userWith(Permission::ViewUsers, Permission::ManageStaffTags))
            ->putJson(route('users.tags.update', $person), ['tags' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }
}
