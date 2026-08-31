<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Аналитика обучения: сколько материала собрано и как его проходят.
 */
final class LearningAnalyticsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Тот, кому доверено обучение: ему и смотреть, как оно идёт. */
    private function trainer(): User
    {
        return $this->userWith(Permission::ViewCourses, Permission::ManageEnrollments);
    }

    /**
     * Курс с уроками и одним учеником, прошедшим половину.
     *
     * @return array{course: Course, learner: User}
     */
    private function courseWithHalfPassed(): array
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lessons = Lesson::factory()->count(4)->create(['module_id' => $module->id]);

        $learner = $this->learner();

        $enrollment = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        foreach ($lessons->take(2) as $lesson) {
            $enrollment->completions()->create([
                'lesson_id' => $lesson->id,
                'completed_at' => now(),
            ]);
        }

        return ['course' => $course, 'learner' => $learner];
    }

    public function test_it_counts_the_material_and_how_far_people_got(): void
    {
        ['course' => $course] = $this->courseWithHalfPassed();

        Regulation::factory()->published()->create();
        Regulation::factory()->create();

        $response = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk();

        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['courses']);
        $this->assertSame(1, $summary['published_courses']);
        $this->assertSame(2, $summary['regulations']);
        $this->assertSame(1, $summary['published_regulations']);
        $this->assertSame(4, $summary['lessons']);

        $this->assertSame(1, $summary['enrollments']);
        $this->assertSame(1, $summary['learners']);
        $this->assertSame(0, $summary['completed']);

        // Два урока из четырёх — половина, и это средний прогресс по курсам.
        $this->assertSame(50, $summary['average_progress']);

        $card = collect($response->json('data.courses'))->firstWhere('id', $course->id);

        $this->assertSame(4, $card['lessons']);
        $this->assertSame(1, $card['enrolled']);
        $this->assertSame(50, $card['average_progress']);
        $this->assertTrue($card['is_published']);
    }

    /**
     * Уволенные из отчёта уходят: иначе прогресс компании падал бы всякий раз,
     * когда человек ушёл, не догуляв курс до конца.
     */
    public function test_a_dismissed_learner_leaves_the_report(): void
    {
        ['learner' => $learner] = $this->courseWithHalfPassed();

        $before = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->json('data.summary');

        $this->assertSame(1, $before['enrollments']);

        $this->actingAs($this->administrator())->postJson(route('users.dismiss', $learner))->assertOk();

        $after = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->json('data.summary');

        $this->assertSame(0, $after['enrollments']);
        $this->assertSame(0, $after['learners']);
        // Материал никуда не делся — ушёл только тот, кто его проходил.
        $this->assertSame(4, $after['lessons']);
    }

    /**
     * Курс без уроков — это ноль процентов, а не деление на ноль.
     */
    public function test_an_empty_course_reports_zero_instead_of_breaking(): void
    {
        $course = Course::factory()->published()->create();

        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $this->learner()->id]);

        $summary = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(0, $summary['average_progress']);
        $this->assertSame(1, $summary['enrollments']);
    }

    public function test_reading_the_knowledge_base_is_not_enough_to_see_the_report(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('analytics.learning'))
            ->assertForbidden();
    }

    /**
     * Право на продажную аналитику к обучению отношения не имеет: там деньги,
     * здесь курсы, и доверяют их разным людям.
     */
    public function test_the_sales_analytics_right_does_not_open_it(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.learning'))
            ->assertForbidden();
    }
}
