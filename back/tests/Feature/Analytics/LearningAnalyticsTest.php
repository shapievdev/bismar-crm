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
use App\Models\Survey;
use App\Models\SurveyCompletion;
use App\Models\SurveyResponse;
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

    /** Урок в опубликованном курсе — на нём проверяют опросы при уроках. */
    private function lessonInCourse(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create(['module_id' => $module->id]);
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

    /* ---------- Опросы ---------- */

    /**
     * Опросы стоят в отчёте рядом с проверками и о том же: как это проходят.
     *
     * Первыми — обязательные: от них зависит зачёт материала.
     */
    public function test_the_report_lists_surveys_with_the_required_ones_first(): void
    {
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $optional = Survey::factory()->forRegulation($document)->withQuestion()->create([
            'title' => 'Необязательный',
        ]);

        $lesson = $this->lessonInCourse();
        $required = Survey::factory()->forLesson($lesson)->required()->withQuestion()->create([
            'title' => 'Обязательный',
        ]);

        $answered = $this->learner();
        SurveyCompletion::query()->create([
            'survey_id' => $optional->id, 'user_id' => $answered->id, 'completed_at' => now(),
        ]);

        $surveys = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk()
            ->json('data.surveys');

        // Обязательный впереди, даже если его ещё никто не прошёл.
        $this->assertSame($required->id, $surveys[0]['id']);
        $this->assertTrue($surveys[0]['is_required']);
        $this->assertSame(0, $surveys[0]['answered']);
        $this->assertSame('lesson', $surveys[0]['owner']);

        $this->assertSame($optional->id, $surveys[1]['id']);
        $this->assertSame(1, $surveys[1]['answered']);
        $this->assertSame('Кассовая дисциплина', $surveys[1]['material']);
        $this->assertSame($document->slug, $surveys[1]['document_slug']);
        $this->assertSame(1, $surveys[1]['questions']);
    }

    /** Уволенный в счёт не идёт: отчёт о тех, кого можно спросить. */
    public function test_a_dismissed_person_is_not_counted_among_those_who_answered(): void
    {
        $lesson = $this->lessonInCourse();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();

        foreach ([$this->learner(), User::factory()->dismissed()->create()] as $person) {
            SurveyCompletion::query()->create([
                'survey_id' => $survey->id, 'user_id' => $person->id, 'completed_at' => now(),
            ]);
        }

        $surveys = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk()
            ->json('data.surveys');

        $this->assertSame(1, $surveys[0]['answered']);
    }

    /** Сводка ответов и список прошедших — одним ответом по одному опросу. */
    public function test_the_report_shows_what_was_answered_and_who_answered(): void
    {
        $lesson = $this->lessonInCourse();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();
        $question = $survey->questions()->with('options')->firstOrFail();
        $option = $question->options->firstOrFail();

        $person = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Мария']);

        SurveyCompletion::query()->create([
            'survey_id' => $survey->id, 'user_id' => $person->id, 'completed_at' => now(),
        ]);
        SurveyResponse::query()->create([
            'survey_id' => $survey->id,
            'user_id' => $person->id,
            'answers' => [(string) $question->getKey() => ['options' => [$option->getKey()]]],
            'submitted_at' => now(),
        ]);

        $data = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.survey', $survey))
            ->assertOk()
            ->assertJsonPath('data.survey.id', $survey->id)
            ->json('data');

        $this->assertSame(1, $data['summary']['answered']);
        $this->assertSame(1, $data['summary']['questions'][0]['options'][0]['count']);

        $this->assertCount(1, $data['people']);
        $this->assertSame('Ёлкина Мария', $data['people'][0]['name']);
    }

    /**
     * Анонимность держится и здесь: прошедшие названы, ответы — нет.
     *
     * Это не оплошность выдачи, а устройство: у анонимного опроса ответы не
     * связаны с человеком в самой базе, и подставить имя сюда неоткуда.
     */
    public function test_an_anonymous_survey_names_who_answered_but_not_what_they_said(): void
    {
        $lesson = $this->lessonInCourse();
        $survey = Survey::factory()->forLesson($lesson)->anonymous()->create();
        $question = $survey->questions()->create([
            'text' => 'Что улучшить?',
            'type' => 'long_text',
            'is_required' => true,
            'position' => 0,
        ]);

        $person = User::factory()->create(['last_name' => 'Сомов', 'first_name' => 'Иван']);

        SurveyCompletion::query()->create([
            'survey_id' => $survey->id, 'user_id' => $person->id, 'completed_at' => now(),
        ]);
        SurveyResponse::query()->create([
            'survey_id' => $survey->id,
            // Человека в строке ответов у анонимного опроса нет вовсе.
            'user_id' => null,
            'answers' => [(string) $question->getKey() => ['text' => 'Ничего, всё понятно']],
            'submitted_at' => now(),
        ]);

        $data = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.survey', $survey))
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['summary']['is_anonymous']);
        $this->assertSame('Ничего, всё понятно', $data['summary']['questions'][0]['texts'][0]['text']);
        $this->assertNull($data['summary']['questions'][0]['texts'][0]['person']);

        // А кто проходил — видно: на этом и стоит обязательный опрос.
        $this->assertSame('Сомов Иван', $data['people'][0]['name']);
    }

    /** Сводка считает опросы и прохождения. */
    public function test_the_summary_counts_surveys(): void
    {
        $lesson = $this->lessonInCourse();
        Survey::factory()->forLesson($lesson)->required()->withQuestion()->create();
        Survey::factory()->forRegulation(Regulation::factory()->published()->create())->create();

        SurveyCompletion::query()->create([
            'survey_id' => Survey::query()->firstOrFail()->id,
            'user_id' => $this->learner()->id,
            'completed_at' => now(),
        ]);

        $summary = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(2, $summary['surveys']);
        $this->assertSame(1, $summary['surveys_required']);
        $this->assertSame(1, $summary['survey_answered']);
    }

    /** Аналитика обучения — тому, кому доверено обучение. */
    public function test_survey_results_are_closed_to_everyone_else(): void
    {
        $lesson = $this->lessonInCourse();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();

        $this->actingAs($this->learner())
            ->getJson(route('analytics.learning.survey', $survey))
            ->assertForbidden();
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

    /* ---------- Люди за цифрой ---------- */

    /**
     * За «не приступали» стоят имена и курсы.
     *
     * Ради них цифру и раскрывают: «трое» не говорит, с кем разговаривать.
     * Уволенных здесь нет по той же причине, по какой их нет в самой цифре.
     */
    public function test_a_number_opens_into_the_people_behind_it(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id]);

        $idle = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Мария']);
        $started = $this->learner();
        $dismissed = User::factory()->dismissed()->create();

        foreach ([[$idle, null], [$started, now()], [$dismissed, null]] as [$person, $startedAt]) {
            Enrollment::factory()->create([
                'course_id' => $course->id,
                'user_id' => $person->id,
                'started_at' => $startedAt,
            ]);
        }

        $response = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.people', ['slice' => 'not-started']))
            ->assertOk()
            ->assertJsonPath('data.slice', 'not-started')
            ->assertJsonPath('data.total', 1);

        $people = $response->json('data.people');

        $this->assertCount(1, $people);
        $this->assertSame($idle->id, $people[0]['user_id']);
        $this->assertSame('Ёлкина Мария', $people[0]['name']);
        $this->assertSame('Кассовая дисциплина', $people[0]['title']);
        $this->assertSame('/lms/'.$course->slug, $people[0]['path']);
        $this->assertSame(0, $people[0]['progress']);
    }

    /**
     * Список и цифра над ним считают одно и то же.
     *
     * Разойдись они — и над списком из четырёх строк стояло бы «пройдено три»,
     * после чего верить перестали бы обоим.
     */
    public function test_the_plan_list_agrees_with_the_number_above_it(): void
    {
        $course = Course::factory()->published()->create();
        $document = Regulation::factory()->published()->create(['title' => 'Приёмка товара']);

        $person = $this->learner();

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $person->id,
            'completed_at' => now(),
        ]);

        foreach ([[$course, 1], [$document, 2]] as [$material, $position]) {
            LearningPlanItem::query()->create([
                'user_id' => $person->id,
                'plannable_type' => $material->getMorphClass(),
                'plannable_id' => $material->id,
                'position' => $position,
            ]);
        }

        $summary = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning'))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(2, $summary['plan_steps']);
        $this->assertSame(1, $summary['plan_done']);

        $people = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.people', ['slice' => 'plan']))
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->json('data.people');

        // Непройденное идёт первым: план работает запретом, и пока шаг открыт,
        // человеку закрыт весь остальной каталог.
        $this->assertSame('Приёмка товара', $people[0]['title']);
        $this->assertSame('в очереди', $people[0]['state']);
        $this->assertSame($document->path(), $people[0]['path']);

        $this->assertSame('пройден', $people[1]['state']);
        $this->assertSame('/lms/'.$course->slug, $people[1]['path']);
    }

    /**
     * Ознакомление ведёт в свой раздел: справочник — не документ.
     */
    public function test_an_acknowledgement_leads_to_its_own_section(): void
    {
        $handbook = Regulation::factory()->handbook()->published()->create(['title' => 'Справочник по кассе']);
        $person = $this->learner();

        $handbook->acknowledgements()->create([
            'user_id' => $person->id,
            'acknowledged_at' => now(),
        ]);

        $people = $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.people', ['slice' => 'acknowledgements']))
            ->assertOk()
            ->json('data.people');

        $this->assertCount(1, $people);
        $this->assertSame('Справочник по кассе', $people[0]['title']);
        $this->assertSame('/lms/handbooks/'.$handbook->slug, $people[0]['path']);
    }

    /**
     * Отвечают все срезы, а не те три, которые кто-то проверил руками.
     *
     * Запросы у них разные и написаны на голом SQL: срез, который никто не
     * открыл, падает молча — и обнаруживается, когда на него нажмут.
     */
    public function test_every_slice_answers(): void
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->attestation()->withQuestions(1)->forLesson($lesson)->create();

        $person = $this->learner();

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $person->id,
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        LearningPlanItem::query()->create([
            'user_id' => $person->id,
            'plannable_type' => $course->getMorphClass(),
            'plannable_id' => $course->id,
            'position' => 1,
        ]);

        Regulation::factory()->published()->create()->acknowledgements()->create([
            'user_id' => $person->id,
            'acknowledged_at' => now(),
        ]);

        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $person->id,
            'score' => 0,
            'passed' => false,
            'answers' => [],
            'completed_at' => now(),
            'review_status' => AttestationStatus::Pending,
        ]);

        $slices = ['not-started', 'completed', 'progress', 'learners', 'plan', 'acknowledgements', 'attestations'];

        foreach ($slices as $slice) {
            $response = $this->actingAs($this->trainer())
                ->getJson(route('analytics.learning.people', ['slice' => $slice]))
                ->assertOk()
                ->assertJsonPath('data.slice', $slice);

            // Кроме «не приступали»: приступили и дошли до конца.
            $this->assertSame(
                $slice === 'not-started' ? 0 : 1,
                $response->json('data.total'),
                "срез {$slice}",
            );
        }
    }

    /** Придуманный срез — не «покажу что-нибудь», а отказ. */
    public function test_an_unknown_slice_is_refused(): void
    {
        $this->actingAs($this->trainer())
            ->getJson(route('analytics.learning.people', ['slice' => 'salaries']))
            ->assertUnprocessable();
    }

    /** Список людей закрыт тем же правом, что и сводка над ним. */
    public function test_the_people_behind_a_number_need_the_same_right(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('analytics.learning.people', ['slice' => 'learners']))
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
