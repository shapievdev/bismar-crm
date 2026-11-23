<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseVisibility;
use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class ReadershipTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_public_document_includes_people_who_never_opened_it_and_filters_section_permissions(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id]);
        $done = $this->learner();
        $waiting = $this->userWith(Permission::ViewDocuments);
        $handbooksOnly = $this->userWith(Permission::ViewHandbooks);
        $gone = $this->learner();
        $gone->forceFill(['dismissed_at' => now()])->save();
        $document->acknowledgements()->create(['user_id' => $done->id, 'acknowledged_at' => now()]);

        $response = $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk();
        $this->assertSame([$done->id], array_column($response->json('data.acknowledged'), 'id'));
        $ids = array_column($response->json('data.pending'), 'id');
        $this->assertContains($waiting->id, $ids);
        $this->assertNotContains($handbooksOnly->id, $ids);
        $this->assertNotContains($gone->id, $ids);
        $this->assertSame(3, $response->json('data.total'));
        $response->assertJsonMissingPath('data.acknowledged.0.quiz');
    }

    public function test_private_admission_combines_people_groups_and_child_departments_without_duplicates(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id, 'visibility' => CourseVisibility::Private]);
        $direct = $this->learner();
        $inGroup = $this->learner();
        $inChild = $this->learner();
        $outsider = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach([$inGroup->id, $direct->id]);
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $child->people()->attach($inChild, ['role' => DepartmentRole::Member->value]);
        $document->members()->attach($direct);
        $document->memberGroups()->attach($group);
        $document->memberDepartments()->attach($parent);
        $response = $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk();
        $ids = array_column($response->json('data.pending'), 'id');
        $this->assertEqualsCanonicalizing([$author->id, $direct->id, $inGroup->id, $inChild->id], $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_revoked_admission_removes_old_confirmations_but_preserves_them_for_readmission(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id, 'visibility' => CourseVisibility::Private]);
        $reader = $this->learner();
        $document->members()->attach($reader);
        $document->acknowledgements()->create(['user_id' => $reader->id, 'acknowledged_at' => now()]);
        $document->members()->detach($reader);
        $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk()->assertJsonCount(0, 'data.acknowledged');
        $document->members()->attach($reader);
        $this->getJson(route('lms.documents.readership', $document))->assertOk()->assertJsonPath('data.acknowledged.0.id', $reader->id);
    }

    public function test_current_group_membership_is_recalculated_each_time(): void
    {
        $author = $this->author();
        $course = Course::factory()->published()->create(['author_id' => $author->id, 'visibility' => CourseVisibility::Private]);
        $group = Group::factory()->create();
        $course->memberGroups()->attach($group);
        $reader = $this->learner();
        $group->members()->attach($reader);
        $this->actingAs($author)->getJson(route('lms.courses.readership', $course))->assertOk()->assertJsonCount(2, 'data.pending');
        $group->members()->detach($reader);
        $this->getJson(route('lms.courses.readership', $course))->assertOk()->assertJsonCount(1, 'data.pending');
    }

    public function test_handbook_uses_its_own_permission_and_has_the_same_lists(): void
    {
        $author = $this->author();
        $handbook = Regulation::factory()->handbook()->published()->create(['author_id' => $author->id]);
        $reader = $this->userWith(Permission::ViewHandbooks);
        $documentReader = $this->userWith(Permission::ViewDocuments);
        $response = $this->actingAs($author)->getJson(route('lms.handbooks.readership', $handbook))->assertOk();
        $ids = array_column($response->json('data.pending'), 'id');
        $this->assertContains($reader->id, $ids);
        $this->assertNotContains($documentReader->id, $ids);
    }

    public function test_course_and_lesson_include_admitted_people_without_enrollment(): void
    {
        $author = $this->author();
        $course = Course::factory()->published()->create(['author_id' => $author->id]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id]);
        $done = $this->learner();
        $waiting = $this->learner();
        $enrollment = Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $done->id, 'completed_at' => now()]);
        $enrollment->completions()->create(['lesson_id' => $lesson->id, 'completed_at' => now()]);
        foreach ([route('lms.courses.readership', $course), route('lms.lessons.readership', $lesson)] as $url) {
            $response = $this->actingAs($author)->getJson($url)->assertOk();
            $this->assertSame([$done->id], array_column($response->json('data.acknowledged'), 'id'));
            $this->assertContains($waiting->id, array_column($response->json('data.pending'), 'id'));
        }
        $this->assertDatabaseMissing('enrollments', ['course_id' => $course->id, 'user_id' => $waiting->id]);
    }

    public function test_reading_the_report_never_marks_anyone_as_done(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id]);
        $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk()->assertJsonCount(0, 'data.acknowledged');
        $this->assertDatabaseCount('regulation_acknowledgements', 0);
    }

    public function test_new_lesson_means_previous_course_completion_is_no_longer_current(): void
    {
        $author = $this->author();
        $course = Course::factory()->published()->create(['author_id' => $author->id]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $first = Lesson::factory()->create(['module_id' => $module->id]);
        $reader = $this->learner();
        $enrollment = Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $reader->id, 'completed_at' => now()]);
        $enrollment->completions()->create(['lesson_id' => $first->id, 'completed_at' => now()]);
        Lesson::factory()->create(['module_id' => $module->id]);
        $response = $this->actingAs($author)->getJson(route('lms.courses.readership', $course))->assertOk();
        $this->assertContains($reader->id, array_column($response->json('data.pending'), 'id'));
        $this->assertNull(collect($response->json('data.pending'))->firstWhere('id', $reader->id)['acknowledged_at']);
    }

    public function test_ordinary_reader_cannot_get_lists_and_unadmitted_editor_cannot_probe_a_private_material(): void
    {
        $author = $this->author();
        $course = Course::factory()->published()->create(['author_id' => $author->id]);
        $document = Regulation::factory()->published()->create(['author_id' => $author->id]);
        $this->actingAs($this->learner())->getJson(route('lms.courses.readership', $course))->assertForbidden();
        $this->getJson(route('lms.documents.readership', $document))->assertForbidden();
        $document->update(['visibility' => CourseVisibility::Private]);
        $this->actingAs($this->author())->getJson(route('lms.documents.readership', $document))->assertForbidden();
    }

    public function test_draft_does_not_demand_acknowledgement_from_people_who_cannot_read_it(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $reader = $this->learner();
        $response = $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk();
        $this->assertNotContains($reader->id, array_column($response->json('data.pending'), 'id'));
    }

    public function test_dismissal_excludes_someone_who_already_confirmed_too(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id]);
        $reader = $this->learner();
        $document->acknowledgements()->create(['user_id' => $reader->id, 'acknowledged_at' => now()]);
        $reader->forceFill(['dismissed_at' => now()])->save();
        $this->actingAs($author)->getJson(route('lms.documents.readership', $document))->assertOk()->assertJsonCount(0, 'data.acknowledged');
        $this->assertDatabaseCount('regulation_acknowledgements', 1);
    }
}
