<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Actions\Lms\CompleteLesson;
use App\Enums\CourseStatus;
use App\Exceptions\ConflictException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Database\Factories\CourseModuleFactory;
use Database\Factories\LessonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * The learner's path: enrol, work through lessons, watch progress move.
 */
final class LearningFlowTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

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

    /**
     * Unpublished material has no publication date, and Postgres sorts nulls
     * first on a descending order — so without NULLS LAST the catalogue opens
     * with a draft or something taken out of circulation.
     */
    public function test_unpublished_material_sorts_below_published(): void
    {
        Course::factory()->create(['title' => 'Черновик']);
        Course::factory()->published()->create(['title' => 'Опубликованный']);
        Course::factory()->create(['title' => 'Архив', 'status' => CourseStatus::Archived]);

        $response = $this->actingAs($this->author())
            ->getJson(route('lms.courses.index'))
            ->assertOk();

        $this->assertSame('Опубликованный', $response->json('data.0.title'));
    }

    public function test_an_author_can_list_a_single_status(): void
    {
        Course::factory()->published()->create();
        $draft = Course::factory()->create();
        Course::factory()->create(['status' => CourseStatus::Archived]);

        $response = $this->actingAs($this->author())
            ->getJson(route('lms.courses.index', ['status' => CourseStatus::Draft->value]))
            ->assertOk();

        $this->assertSame([$draft->slug], array_column($response->json('data'), 'slug'));
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

    public function test_opening_a_lesson_enrols_the_reader_automatically(): void
    {
        $course = Course::factory()->withLessons(2)->create();

        $this->assertSame(0, Enrollment::query()->count());

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $course->lessons()->first()))
            ->assertOk();

        // A knowledge base has no sign-up step: reading starts the tracking.
        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_reading_draft_material_does_not_create_an_enrolment(): void
    {
        $course = Course::factory()->create();
        CourseModuleFactory::new()->create(['course_id' => $course->id]);
        $lesson = LessonFactory::new()->create(['module_id' => $course->modules()->first()->id]);

        // Authors preview drafts; that is not progress worth recording.
        $this->actingAs($this->author())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();

        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_a_lesson_in_unpublished_material_cannot_be_completed(): void
    {
        $course = Course::factory()->create();
        CourseModuleFactory::new()->create(['course_id' => $course->id]);
        $lesson = LessonFactory::new()->create(['module_id' => $course->modules()->first()->id]);

        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertConflict();
    }

    /**
     * With enrolment created on demand, the HTTP layer can no longer present a
     * lesson/enrolment mismatch — but the guard still has to hold, so it is
     * asserted directly against the action.
     */
    public function test_a_lesson_outside_the_enrolments_course_is_refused(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $other = Course::factory()->withLessons(1)->create();

        $enrollment = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->learner()->id,
        ]);

        $this->expectException(ConflictException::class);

        app(CompleteLesson::class)->handle($enrollment, $other->lessons()->firstOrFail());
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

    /**
     * Открыть урок — ещё не взяться за курс.
     *
     * Запись при этом заводится, чтобы прогресс было куда писать. Но «мои
     * материалы», построенные по одним записям, превращались в историю
     * просмотров: туда попадало всё, во что человек когда-либо заглянул.
     */
    public function test_merely_opening_a_lesson_does_not_put_the_course_in_my_courses(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->getJson(route('lms.lessons.show', $course->lessons()->first()))
            ->assertOk();

        // Запись заведена — прогрессу есть куда писаться.
        $this->assertSame(1, Enrollment::query()->where('user_id', $learner->id)->count());

        $this->actingAs($learner)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Пройденный урок — это «взялся», даже если кнопку начала не нажимали. */
    public function test_completing_a_lesson_puts_the_course_in_my_courses(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();
        $lesson = $course->lessons()->first();

        $this->actingAs($learner)->getJson(route('lms.lessons.show', $lesson))->assertOk();
        $this->actingAs($learner)->postJson(route('lms.lessons.complete', $lesson))->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Нажатие «Начать обучение» — тоже, и без единого пройденного урока. */
    public function test_pressing_start_puts_the_course_in_my_courses(): void
    {
        $course = Course::factory()->withLessons(2)->create();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
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

    private function lessonFor(Course $course): Lesson
    {
        return $course->lessons()->firstOrFail();
    }
}
