<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Role as RoleEnum;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

/**
 * The learner's path: enrol, work through lessons, watch progress move.
 */
final class LearningFlowTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_a_learner_sees_only_published_courses(): void
    {
        $published = Course::factory()->published()->create();
        Course::factory()->create();

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index'))
            ->assertOk();

        $this->assertSame([$published->slug], array_column($response->json('data'), 'slug'));
    }

    public function test_an_author_also_sees_drafts(): void
    {
        Course::factory()->published()->create();
        Course::factory()->create();

        $this->actingAs($this->author())
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_draft_course_is_not_revealed_to_a_learner(): void
    {
        $draft = Course::factory()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.show', $draft))
            ->assertNotFound();
    }

    public function test_a_learner_can_enrol_and_starts_at_zero_progress(): void
    {
        $course = Course::factory()->withLessons(4)->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.enroll', $course))
            ->assertCreated()
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.is_completed', false);
    }

    public function test_enrolling_twice_does_not_duplicate_or_reset(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();
        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_an_unpublished_course_cannot_be_enrolled_on(): void
    {
        $course = Course::factory()->create();

        // Authors can see the draft, so this proves the guard is in the action
        // and not merely a side effect of the course being hidden.
        $this->actingAs($this->author())
            ->postJson(route('lms.enroll', $course))
            ->assertConflict();
    }

    public function test_progress_advances_as_lessons_are_completed(): void
    {
        $course = Course::factory()->withLessons(4)->create();
        $learner = $this->learner();
        $lessons = $course->lessons()->get();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lessons[0]))
            ->assertOk()
            ->assertJsonPath('data.progress', 25)
            ->assertJsonPath('data.is_completed', false);

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lessons[1]))
            ->assertOk()
            ->assertJsonPath('data.progress', 50);
    }

    public function test_completing_every_lesson_completes_the_course(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        foreach ($course->lessons()->get() as $lesson) {
            $this->actingAs($learner)->postJson(route('lms.lessons.complete', $lesson))->assertOk();
        }

        $this->assertNotNull(Enrollment::query()->sole()->completed_at);
    }

    public function test_completing_a_lesson_twice_does_not_double_count(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();
        $lesson = $course->lessons()->first();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();
        $this->actingAs($learner)->postJson(route('lms.lessons.complete', $lesson))->assertOk();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertOk()
            ->assertJsonPath('data.progress', 50);
    }

    public function test_adding_a_lesson_reopens_a_completed_course(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();
        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $course->lessons()->first()))
            ->assertOk();

        $this->assertNotNull(Enrollment::query()->sole()->completed_at);

        $module = $course->modules()->first();

        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.store', $module), [
                'title' => 'Новый урок',
                'content' => 'Текст.',
            ])
            ->assertCreated();

        // The learner has not seen the new lesson, so the course is unfinished.
        $this->assertNull(Enrollment::query()->sole()->completed_at);
    }

    public function test_a_lesson_cannot_be_completed_without_enrolling(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.lessons.complete', $course->lessons()->first()))
            ->assertConflict();
    }

    public function test_a_lesson_from_another_course_cannot_be_completed(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $other = Course::factory()->withLessons(1)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $other->lessons()->first()))
            ->assertConflict();
    }

    public function test_a_lesson_with_a_quiz_cannot_be_ticked_off_without_passing(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->first();
        Quiz::factory()->withQuestions(2)->create(['lesson_id' => $lesson->id]);

        $learner = $this->learner();
        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertConflict();
    }

    public function test_my_courses_lists_the_learners_own_enrolments_with_progress(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();
        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $course->lessons()->first()))
            ->assertOk();

        // Another learner's enrolment must not leak into this list.
        Enrollment::factory()->create(['course_id' => $course->id]);

        $this->actingAs($learner)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.progress', 50);
    }

    public function test_a_course_with_no_lessons_reports_zero_not_complete(): void
    {
        $course = Course::factory()->published()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.enroll', $course))
            ->assertCreated()
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.is_completed', false);
    }

    public function test_a_user_without_course_access_is_refused(): void
    {
        Course::factory()->published()->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('lms.courses.index'))
            ->assertForbidden();
    }

    private function learner(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Viewer->value);
    }

    private function author(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Manager->value);
    }

    private function lessonFor(Course $course): Lesson
    {
        return $course->lessons()->firstOrFail();
    }
}
