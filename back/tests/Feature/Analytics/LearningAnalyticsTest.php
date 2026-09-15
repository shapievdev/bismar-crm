<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\AttestationStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationVersion;
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
        $this->assertSame(2, $summary['documents']);
        $this->assertSame(1, $summary['published_documents']);
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
        $this->assertSame('lesson', $lessonRow['owner']);
        $this->assertSame('Первый звонок', $lessonRow['material']);
        $this->assertSame('Работа с клиентом', $lessonRow['course_title']);
        $this->assertSame(2, $lessonRow['questions']);
        $this->assertSame(2, $lessonRow['attempted']);
        $this->assertSame(1, $lessonRow['passed']);
        // Средний балл — по лучшей попытке каждого: (100 + 0) / 2.
        $this->assertSame(50, $lessonRow['average_score']);

        $documentRow = $quizzes[$documentQuiz->id];
        $this->assertSame('regulation', $documentRow['owner']);
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

    /* ---------- Разделы, охват и аттестации ---------- */

    /**
     * Документы и справочники считаются врозь.
     *
     * Разделы разные и права у них свои (2026-09-11); сложенные в одно число,
     * они отвечают на вопрос, которого никто не задавал.
     */
    public function test_documents_and_handbooks_are_counted_apart(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        Regulation::factory()->create(['title' => 'Черновик документа']);
        Regulation::factory()->handbook()->published()->create(['title' => 'Справочник по кассе']);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk();

        $summary = $response->json('data.summary');

        $this->assertSame(2, $summary['documents']);
        $this->assertSame(1, $summary['published_documents']);
        $this->assertSame(1, $summary['handbooks']);
        $this->assertSame(1, $summary['published_handbooks']);

        $this->assertSame(
            ['Кассовая дисциплина', 'Черновик документа'],
            collect($response->json('data.documents'))->pluck('title')->sort()->values()->all(),
        );

        $this->assertSame(
            ['Справочник по кассе'],
            collect($response->json('data.handbooks'))->pluck('title')->all(),
        );
    }

    /**
     * Круг допущенных — записанные плюс те, кому курс назначен планом.
     *
     * Ради этого числа отчёт и переписывали: «прошли семеро» ничего не значит,
     * пока не сказано, из скольких, — а назначенный планом допущен, даже если
     * записи у него ещё нет.
     */
    public function test_the_circle_counts_those_the_course_is_planned_for(): void
    {
        $course = Course::factory()->published()->create();
        $enrolled = $this->learner();
        $planned = $this->learner();

        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $enrolled->id]);

        LearningPlanItem::query()->create([
            'user_id' => $planned->id,
            'plannable_type' => $course->getMorphClass(),
            'plannable_id' => $course->id,
            'position' => 1,
        ]);

        $card = collect(
            $this->actingAs($this->trainer())
                ->getJson(route('analytics.learning'))
                ->assertOk()
                ->json('data.courses'),
        )->firstWhere('id', $course->id);

        // Записан один, а допущены двое: второму курс назначен планом.
        $this->assertSame(1, $card['enrolled']);
        $this->assertSame(2, $card['audience']);
        $this->assertSame(0, $card['completed']);
    }

    /**
     * Назначено и начато — разные числа.
     *
     * Запись, к которой не приступали, это не «медленно идёт», а «не открывали
     * вовсе», и разговаривать по ней надо иначе.
     */
    public function test_untouched_enrollments_are_counted_apart(): void
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id]);

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->learner()->id,
            'started_at' => now(),
        ]);

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->learner()->id,
            'started_at' => null,
        ]);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk();

        $this->assertSame(2, $response->json('data.summary.enrollments'));
        $this->assertSame(1, $response->json('data.summary.not_started'));

        $card = collect($response->json('data.courses'))->firstWhere('id', $course->id);

        $this->assertSame(2, $card['enrolled']);
        $this->assertSame(1, $card['started']);
    }

    /**
     * Аттестация, ждущая человека, — не «не сдал».
     *
     * Работа отправлена, а проверяющий до неё ещё не дошёл: спрашивать надо не
     * с отправившего. Это и есть число, ради которого отчёт открывают утром.
     */
    public function test_an_attestation_awaiting_review_is_not_a_failure(): void
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id]);

        $quiz = Quiz::factory()->attestation()->withQuestions(1)->forLesson($lesson)->create();
        $waiting = $this->learner();

        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $waiting->id,
            'score' => 0,
            'passed' => false,
            'answers' => [],
            'completed_at' => now(),
            'review_status' => AttestationStatus::Pending,
        ]);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk();

        $this->assertSame(1, $response->json('data.summary.attestations'));
        $this->assertSame(1, $response->json('data.summary.attestations_pending'));

        $row = collect($response->json('data.quizzes'))->firstWhere('id', $quiz->id);

        $this->assertTrue($row['is_attestation']);
        $this->assertSame(1, $row['attempted']);
        $this->assertSame(0, $row['passed']);
        $this->assertSame(1, $row['pending']);

        $person = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.quiz', $quiz))
            ->assertOk()
            ->json('data.people.0');

        $this->assertTrue($person['awaiting']);
        $this->assertFalse($person['passed']);
    }

    /**
     * Проверка при версии документа — тоже проверка.
     *
     * Версии завелись позже отчёта, и висящие на них проверки не показывались
     * нигде: заведённая аттестация была невидима и для того, кто её проверяет.
     */
    public function test_a_quiz_on_a_document_version_is_reported(): void
    {
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $version = RegulationVersion::query()->create([
            'regulation_id' => $document->id,
            'name' => 'Для кассиров',
            'position' => 1,
        ]);

        $quiz = Quiz::factory()->withQuestions(1)->forVersion($version)->create(['title' => 'Проверка кассира']);

        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->learner()->id,
            'score' => 100,
            'passed' => true,
            'answers' => [],
            'completed_at' => now(),
        ]);

        $row = collect(
            $this->actingAs($this->trainer())
                ->getJson(route('analytics.learning'))
                ->assertOk()
                ->json('data.quizzes'),
        )->firstWhere('id', $quiz->id);

        $this->assertNotNull($row, 'Проверка версии в отчёт не попала.');
        $this->assertSame('regulation_version', $row['owner']);
        $this->assertSame('Кассовая дисциплина', $row['material']);
        $this->assertSame('Для кассиров', $row['version_name']);
        $this->assertSame($document->slug, $row['document_slug']);
        $this->assertSame(1, $row['passed']);
    }

    /**
     * Проходивший проверку документа попадает в круг допущенных.
     *
     * Не сдавший не ознакомлен, но к документу он приходил, и не считать его
     * значит потерять ровно того, с кем надо разговаривать.
     */
    public function test_someone_who_only_tried_the_check_is_still_in_the_circle(): void
    {
        $document = Regulation::factory()->published()->create();
        $quiz = Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->learner()->id,
            'score' => 0,
            'passed' => false,
            'answers' => [],
            'completed_at' => now(),
        ]);

        $card = collect(
            $this->actingAs($this->trainer())
                ->getJson(route('analytics.learning'))
                ->assertOk()
                ->json('data.documents'),
        )->firstWhere('id', $document->id);

        $this->assertSame(1, $card['audience']);
        $this->assertSame(0, $card['acknowledged']);
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
