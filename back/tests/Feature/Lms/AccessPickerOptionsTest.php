<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\DepartmentRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\Group;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class AccessPickerOptionsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_authors_can_select_all_current_employees_of_a_job_without_staff_management_rights(): void
    {
        $author = $this->author();
        $author->update(['job_title' => 'Продавец']);
        $course = Course::factory()->published()->closed()->create(['author_id' => $author->id]);
        $people = User::factory()->count(25)->create(['job_title' => 'Продавец']);
        $existing = User::factory()->create(['job_title' => 'Продавец']);
        $course->members()->attach($existing);
        User::factory()->create(['job_title' => 'Продавец', 'dismissed_at' => now()]);
        User::factory()->create(['job_title' => 'Продавец'])->delete();
        User::factory()->create(['job_title' => 'Старший продавец']);

        $response = $this->actingAs($author)->getJson(route('lms.courses.access.options', [$course, 'job_title' => 'Продавец']))
            ->assertOk()->assertJsonCount(25, 'data.people');
        $this->assertEqualsCanonicalizing($people->modelKeys(), array_column($response->json('data.people'), 'id'));
        $this->assertContains('Продавец', $response->json('data.job_titles'));

        $this->getJson(route('lms.courses.access.options', $course))->assertOk()->assertJsonCount(0, 'data.people');
        $person = $people->first();
        $this->getJson(route('lms.courses.access.options', [$course, 'job_title' => 'Продавец', 'search' => $person->email]))
            ->assertOk()->assertJsonCount(1, 'data.people')->assertJsonPath('data.people.0.id', $person->id);
    }

    public function test_documents_and_handbooks_expose_the_structure_only_to_access_managers(): void
    {
        $author = $this->author();
        $root = Department::factory()->create(['name' => 'Компания', 'parent_id' => null]);
        $sales = Department::factory()->create(['name' => 'Продажи', 'parent_id' => $root->id]);
        $shop = Department::factory()->create(['name' => 'Магазин', 'parent_id' => $sales->id]);
        $person = User::factory()->create(['job_title' => 'Консультант']);

        foreach (['documents' => 'document', 'handbooks' => 'handbook'] as $section => $kind) {
            $material = Regulation::factory()->published()->closed()->create(['author_id' => $author->id, 'kind' => $kind]);
            $response = $this->actingAs($author)->getJson(route("lms.$section.access.options", [$material, 'job_title' => 'Консультант']))
                ->assertOk()->assertJsonPath('data.people.0.id', $person->id);
            $branch = collect($response->json('data.departments'))->firstWhere('id', $root->id);
            $this->assertSame($sales->id, $branch['children'][0]['id']);
            $this->assertSame($shop->id, $branch['children'][0]['children'][0]['id']);
            $this->assertSame('Продажи', $branch['children'][0]['children'][0]['parent']);
            $this->actingAs($this->learner())->getJson(route("lms.$section.access.options", $material))->assertForbidden();
        }

        $course = Course::factory()->published()->closed()->create(['author_id' => $author->id]);
        $this->actingAs($this->learner())->getJson(route('lms.courses.access.options', $course))->assertNotFound();
        $reader = $this->learner();
        $course->members()->attach($reader);
        $this->actingAs($reader)->getJson(route('lms.courses.access.options', $course))->assertForbidden();
        $this->actingAs($author)->getJson(route('lms.courses.access.options', [$course, 'job_title' => str_repeat('x', 256)]))->assertUnprocessable();
    }

    public function test_bulk_selection_preserves_groups_and_department_access_follows_current_membership(): void
    {
        $author = $this->author();
        $material = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);
        $named = $this->learner();
        $named->update(['job_title' => 'Продавец']);
        $group = Group::factory()->create();
        $material->memberGroups()->attach($group);
        $department = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $department->id]);

        $selected = $this->actingAs($author)->getJson(route('lms.documents.access.options', [$material, 'job_title' => 'Продавец']))
            ->assertOk()->json('data.people');
        $this->putJson(route('lms.documents.access.update', $material), [
            'members' => array_column($selected, 'id'),
            'groups' => [$group->id],
            'departments' => [$department->id],
        ])->assertOk()->assertJsonCount(1, 'data.groups')->assertJsonCount(1, 'data.people')->assertJsonCount(1, 'data.departments');

        $named->update(['job_title' => 'Менеджер']);
        $this->actingAs($named)->getJson(route('lms.documents.show', $material))->assertOk();
        $newcomer = $this->learner();
        $child->people()->attach($newcomer, ['role' => DepartmentRole::Member->value]);
        $this->actingAs($newcomer)->getJson(route('lms.documents.show', $material))->assertOk();
        $child->people()->detach($newcomer);
        $this->actingAs($newcomer)->getJson(route('lms.documents.show', $material))->assertForbidden();
    }
}
