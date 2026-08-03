<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Role as RoleEnum;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class CategoryTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_categories_are_returned_as_a_tree(): void
    {
        $root = Category::factory()->create(['name' => 'Продажи', 'position' => 0]);
        Category::factory()->create(['name' => 'Возражения', 'parent_id' => $root->id]);
        Category::factory()->create(['name' => 'Регламенты', 'position' => 1]);

        $response = $this->actingAs($this->reader())
            ->getJson(route('lms.categories.index'))
            ->assertOk();

        // Only roots at the top level; children hang off their parent.
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Возражения', $response->json('data.0.children.0.name'));
    }

    public function test_a_category_can_be_nested_under_another(): void
    {
        $parent = Category::factory()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.categories.store'), [
                'name' => 'Подраздел',
                'parent_id' => $parent->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_a_category_cannot_be_nested_under_itself(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.categories.update', $category), [
                'name' => $category->name,
                'parent_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_category_cannot_be_nested_under_its_own_descendant(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);

        // Moving the root under its grandchild would detach the whole branch.
        $this->actingAs($this->author())
            ->putJson(route('lms.categories.update', $root), [
                'name' => $root->name,
                'parent_id' => $grandchild->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_filtering_by_a_parent_category_includes_nested_material(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $inParent = Course::factory()->published()->create(['category_id' => $parent->id]);
        $inChild = Course::factory()->published()->create(['category_id' => $child->id]);
        Course::factory()->published()->create();

        $response = $this->actingAs($this->reader())
            ->getJson(route('lms.courses.index', ['category' => $parent->slug]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$inParent->slug, $inChild->slug],
            array_column($response->json('data'), 'slug'),
        );
    }

    public function test_deleting_a_parent_lifts_its_children_rather_than_removing_them(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->author())
            ->deleteJson(route('lms.categories.destroy', $parent))
            ->assertNoContent();

        $this->assertNull($child->refresh()->parent_id);
        $this->assertDatabaseHas('categories', ['id' => $child->id]);
    }

    public function test_a_reader_cannot_manage_categories(): void
    {
        $this->actingAs($this->reader())
            ->postJson(route('lms.categories.store'), ['name' => 'Своя'])
            ->assertForbidden();
    }

    private function reader(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Viewer->value);
    }

    private function author(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Manager->value);
    }
}
