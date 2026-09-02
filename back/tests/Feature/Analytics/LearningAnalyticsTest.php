<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
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

    /* ---------- Отчёт по тестам ---------- */

    /**
     * Один список на тесты уроков и проверки документов: устройство у них
     * общее, и вопрос «как это проходят» тоже.
     */
    public function test_the_report_counts_how_quizzes_are_passed(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Работа с клиентом']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id, 'title' => 'Первый звонок']);
        $lessonQuiz = Quiz::factory()->withQuestions(2)->forLesson($lesson)->create();

        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $documentQuiz = Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $passer = $this->learner();
        $failer = $this->learner();

        // Сдал со второй попытки: человек в отчёте один, попыток у него две.
        QuizAttempt::query()->create([
            'quiz_id' => $lessonQuiz->id, 'user_id' => $passer->id,
            'score' => 50, 'passed' => false, 'answers' => [], 'completed_at' => now()->subHour(),
        ]);
        QuizAttempt::query()->create([
            'quiz_id' => $lessonQuiz->id, 'user_id' => $passer->id,
            'score' => 100, 'passed' => true, 'answers' => [], 'completed_at' => now(),
        ]);
        QuizAttempt::query()->create([
            'quiz_id' => $lessonQuiz->id, 'user_id' => $failer->id,
            'score' => 0, 'passed' => false, 'answers' => [], 'completed_at' => now(),
        ]);
        QuizAttempt::query()->create([
            'quiz_id' => $documentQuiz->id, 'user_id' => $passer->id,
            'score' => 100, 'passed' => true, 'answers' => [], 'completed_at' => now(),
        ]);

        $quizzes = collect(
            $this->actingAs($this->trainer())
                ->getJson(route('analytics.learning'))
                ->assertOk()
                ->json('data.quizzes'),
        )->keyBy('id');

        $lessonRow = $quizzes[$lessonQuiz->id];
        $this->assertSame('lesson', $lessonRow['kind']);
        $this->assertSame('Первый звонок', $lessonRow['material']);
        $this->assertSame('Работа с клиентом', $lessonRow['course_title']);
        $this->assertSame(2, $lessonRow['questions']);
        $this->assertSame(2, $lessonRow['attempted']);
        $this->assertSame(1, $lessonRow['passed']);
        // Средний балл — по лучшей попытке каждого: (100 + 0) / 2.
        $this->assertSame(50, $lessonRow['average_score']);

        $documentRow = $quizzes[$documentQuiz->id];
        $this->assertSame('regulation', $documentRow['kind']);
        $this->assertSame('Кассовая дисциплина', $documentRow['material']);
        $this->assertSame($document->slug, $documentRow['document_slug']);
        $this->assertSame(1, $documentRow['passed']);
    }

    public function test_the_report_shows_who_passed_a_quiz(): void
    {
        $document = Regulation::factory()->published()->create();
        $quiz = Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $passer = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Мария']);
        $failer = User::factory()->create(['last_name' => 'Яковлев', 'first_name' => 'Пётр']);
        $dismissed = User::factory()->dismissed()->create();

        foreach ([[$passer, 100, true], [$failer, 40, false], [$dismissed, 100, true]] as [$person, $score, $passed]) {
            QuizAttempt::query()->create([
                'quiz_id' => $quiz->id, 'user_id' => $person->id,
                'score' => $score, 'passed' => $passed, 'answers' => [], 'completed_at' => now(),
            ]);
        }

        $people = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.quiz', $quiz))
            ->assertOk()
            ->assertJsonPath('data.quiz.id', $quiz->id)
            ->json('data.people');

        // Уволенный из отчёта уходит — спрашивать с него уже нечего.
        $this->assertCount(2, $people);

        // Не сдавшие идут первыми: отчёт открывают ради них.
        $this->assertSame($failer->id, $people[0]['id']);
        $this->assertFalse($people[0]['passed']);
        $this->assertSame(40, $people[0]['best_score']);

        $this->assertSame($passer->id, $people[1]['id']);
        $this->assertTrue($people[1]['passed']);
        $this->assertSame('Ёлкина Мария', $people[1]['name']);
        $this->assertSame(1, $people[1]['attempts']);
    }

    public function test_the_results_of_a_quiz_are_closed_without_the_right(): void
    {
        $document = Regulation::factory()->published()->create();
        $quiz = Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $this->actingAs($this->learner())
            ->getJson(route('analytics.learning.quiz', $quiz))
            ->assertForbidden();
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
