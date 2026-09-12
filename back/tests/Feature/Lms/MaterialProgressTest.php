<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\LearningPlanItem;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Как материал проходят — внизу его же страницы.
 *
 * Круг людей здесь один на все три отчёта: начавшие и те, кому материал
 * назначен планом (решение пользователя 2026-09-12). Весь штат в него не
 * входит: документ открыт всякому, кто читает базу знаний, и «ещё не
 * ознакомился» означало бы расписание компании вместо отчёта.
 */
final class MaterialProgressTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Курс ---------- */

    public function test_the_course_report_counts_the_enrolled_and_the_assigned(): void
    {
        [$course, $lessons] = $this->courseWithLessons(4);

        $started = User::factory()->create(['last_name' => 'Антонов', 'first_name' => 'Илья']);
        $assigned = User::factory()->create(['last_name' => 'Борисова', 'first_name' => 'Вера']);

        $enrollment = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $started->id,
            'started_at' => now(),
        ]);

        foreach ($lessons->take(2) as $lesson) {
            $enrollment->completions()->create(['lesson_id' => $lesson->id, 'completed_at' => now()]);
        }

        $this->plan($assigned, 'course', (int) $course->id);

        $response = $this->actingAs($this->administrator())
            ->getJson(route('lms.courses.progress', $course))
            ->assertOk();

        $summary = $response->json('data.summary');

        $this->assertSame(2, $summary['people']);
        $this->assertSame(4, $summary['lessons']);
        $this->assertSame(1, $summary['in_progress']);
        $this->assertSame(1, $summary['not_started']);
        // Два урока из четырёх у одного и ноль у второго — в среднем четверть.
        $this->assertSame(25, $summary['average_progress']);

        $people = collect($response->json('data.people'))->keyBy('id');

        $this->assertSame(50, $people[$started->id]['progress']);
        $this->assertSame(2, $people[$started->id]['lessons_done']);
        $this->assertSame('in_progress', $people[$started->id]['status']);
        $this->assertFalse($people[$started->id]['in_plan']);

        // Назначенный планом стоит в списке, хотя курс ещё не открывал: иначе
        // курс выглядел бы пройденным целиком — некому проваливать.
        $this->assertSame('not_started', $people[$assigned->id]['status']);
        $this->assertTrue($people[$assigned->id]['in_plan']);
        $this->assertFalse($people[$assigned->id]['is_enrolled']);
    }

    /** Вперёд идут те, у кого дело не сдвинулось: ради них отчёт и открывают. */
    public function test_the_unfinished_come_first(): void
    {
        [$course, $lessons] = $this->courseWithLessons(1);

        $done = User::factory()->create(['last_name' => 'Абрамов']);
        $waiting = User::factory()->create(['last_name' => 'Яковлев']);

        $finished = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $done->id,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $finished->completions()->create(['lesson_id' => $lessons->first()->id, 'completed_at' => now()]);

        $this->plan($waiting, 'course', (int) $course->id);

        $people = $this->actingAs($this->administrator())
            ->getJson(route('lms.courses.progress', $course))
            ->assertOk()
            ->json('data.people');

        $this->assertSame($waiting->id, $people[0]['id']);
        $this->assertSame($done->id, $people[1]['id']);
        $this->assertSame('completed', $people[1]['status']);
        $this->assertSame(100, $people[1]['progress']);
    }

    /** Уволенный уходит из отчёта: спрашивать с него уже нечего. */
    public function test_a_dismissed_learner_leaves_the_report(): void
    {
        [$course] = $this->courseWithLessons(2);

        $gone = User::factory()->dismissed()->create();
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $gone->id]);

        $this->actingAs($this->administrator())
            ->getJson(route('lms.courses.progress', $course))
            ->assertOk()
            ->assertJsonPath('data.summary.people', 0);
    }

    /** Свежесобранный курс — это ноль процентов, а не деление на ноль. */
    public function test_a_course_without_lessons_reports_zero(): void
    {
        $course = Course::factory()->published()->create();

        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $this->learner()->id]);

        $this->actingAs($this->administrator())
            ->getJson(route('lms.courses.progress', $course))
            ->assertOk()
            ->assertJsonPath('data.summary.average_progress', 0)
            ->assertJsonPath('data.people.0.progress', 0);
    }

    /* ---------- Один человек по урокам ---------- */

    public function test_a_learner_row_opens_into_lessons_and_quiz_results(): void
    {
        [$course, $lessons] = $this->courseWithLessons(2);

        $quiz = Quiz::factory()->withQuestions(1)->forLesson($lessons->first())->create();

        $learner = $this->learner();
        $enrollment = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'started_at' => now(),
        ]);
        $enrollment->completions()->create(['lesson_id' => $lessons->first()->id, 'completed_at' => now()]);

        $this->attempt($quiz, $learner, 40, false, now()->subHour());
        $this->attempt($quiz, $learner, 100, true, now());

        $response = $this->actingAs($this->administrator())
            ->getJson(route('lms.courses.progress.learner', [$course, $learner]))
            ->assertOk()
            ->assertJsonPath('data.learner.id', $learner->id);

        $rows = $response->json('data.lessons');

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['is_done']);
        $this->assertFalse($rows[1]['is_done']);

        // Лучшая попытка, а не последняя: пересдача затем и существует.
        $this->assertSame(2, $rows[0]['quiz']['attempts']);
        $this->assertSame(100, $rows[0]['quiz']['best_score']);
        $this->assertTrue($rows[0]['quiz']['passed']);

        // У урока без теста — пусто, а не ноль баллов: ноль читался бы провалом.
        $this->assertNull($rows[1]['quiz']);
    }

    /* ---------- Один урок ---------- */

    public function test_the_lesson_report_says_who_closed_it(): void
    {
        [$course, $lessons] = $this->courseWithLessons(2);
        $lesson = $lessons->first();

        $quiz = Quiz::factory()->withQuestions(1)->forLesson($lesson)->create();

        $closed = $this->learner();
        $stuck = $this->learner();

        $enrollment = Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $closed->id,
            'started_at' => now(),
        ]);
        $enrollment->completions()->create(['lesson_id' => $lesson->id, 'completed_at' => now()]);

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $stuck->id,
            'started_at' => now(),
        ]);

        $this->attempt($quiz, $closed, 100, true);
        $this->attempt($quiz, $stuck, 0, false);

        $response = $this->actingAs($this->administrator())
            ->getJson(route('lms.lessons.progress', $lesson))
            ->assertOk();

        $this->assertSame(2, $response->json('data.summary.people'));
        $this->assertSame(1, $response->json('data.summary.done'));
        $this->assertSame(2, $response->json('data.summary.attempted'));
        $this->assertSame(1, $response->json('data.summary.passed'));

        $people = collect($response->json('data.people'))->keyBy('id');

        $this->assertTrue($people[$closed->id]['is_done']);
        $this->assertNotNull($people[$closed->id]['done_at']);
        $this->assertFalse($people[$stuck->id]['is_done']);
        $this->assertFalse($people[$stuck->id]['quiz']['passed']);
    }

    /* ---------- Документ и справочник ---------- */

    public function test_the_document_report_counts_readers_and_those_who_only_tried(): void
    {
        $document = Regulation::factory()->published()->create();
        $quiz = Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $read = $this->learner();
        $tried = $this->learner();
        $assigned = $this->learner();

        $document->acknowledgements()->create(['user_id' => $read->id, 'acknowledged_at' => now()]);
        $this->attempt($quiz, $read, 100, true);

        // Проверку писал, но не сдал: ознакомления нет, а разговаривать надо
        // именно с ним — молчать о таком значит терять единственного адресата.
        $this->attempt($quiz, $tried, 0, false);

        $this->plan($assigned, 'regulation', (int) $document->id);

        $response = $this->actingAs($this->administrator())
            ->getJson(route('lms.documents.progress', $document))
            ->assertOk();

        $this->assertSame(3, $response->json('data.summary.people'));
        $this->assertSame(1, $response->json('data.summary.done'));
        $this->assertSame(2, $response->json('data.summary.attempted'));

        $people = collect($response->json('data.people'))->keyBy('id');

        $this->assertTrue($people[$read->id]['is_done']);
        $this->assertSame(100, $people[$read->id]['quiz']['best_score']);
        $this->assertFalse($people[$tried->id]['is_done']);
        $this->assertTrue($people[$assigned->id]['in_plan']);
        $this->assertSame(0, $people[$assigned->id]['quiz']['attempts']);
    }

    /**
     * У документа с версиями проверок столько, сколько версий: каждый проходит
     * свою, и отчёт смотрит на все разом. Иначе сдавший проверку своей версии
     * числился бы непроходившим, а разговаривать пошли бы не с тем.
     */
    public function test_the_report_counts_the_checks_of_every_version(): void
    {
        $document = Regulation::factory()->published()->create();

        // Общая проверка у документа есть, а у версии — своя.
        Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $group = Group::factory()->create(['name' => 'Розница']);
        $salesman = $this->learner();
        $group->members()->attach($salesman);

        /** @var RegulationVersion $version */
        $version = $document->versions()->create(['name' => 'Для розницы', 'position' => 1]);
        $version->groups()->sync([$group->id]);

        $versionQuiz = Quiz::factory()->withQuestions(1)->create([
            'quizzable_type' => 'regulation_version',
            'quizzable_id' => $version->id,
        ]);

        $this->attempt($versionQuiz, $salesman, 100, true);
        $document->acknowledgements()->create([
            'user_id' => $salesman->id,
            'version_id' => $version->id,
            'acknowledged_at' => now(),
        ]);

        $response = $this->actingAs($this->administrator())
            ->getJson(route('lms.documents.progress', $document))
            ->assertOk();

        $this->assertSame(1, $response->json('data.summary.people'));
        $this->assertSame(1, $response->json('data.summary.passed'));

        $person = $response->json('data.people.0');

        $this->assertTrue($person['is_done']);
        $this->assertTrue($person['quiz']['passed']);

        // В строке видно, чью версию человек читал: у кого какая, там и
        // спрашивать.
        $this->assertSame('Для розницы', $person['version']);
    }

    /** У документа без проверки отчёт остаётся про ознакомление. */
    public function test_a_document_without_a_check_reports_acknowledgements_alone(): void
    {
        $handbook = Regulation::factory()->handbook()->published()->create();
        $reader = $this->learner();

        $handbook->acknowledgements()->create(['user_id' => $reader->id, 'acknowledged_at' => now()]);

        $this->actingAs($this->administrator())
            ->getJson(route('lms.handbooks.progress', $handbook))
            ->assertOk()
            ->assertJsonPath('data.summary.done', 1)
            ->assertJsonPath('data.people.0.quiz', null);
    }

    /** Раздел чужого материала отвечает «не найдено», а не открывает его. */
    public function test_a_handbook_address_does_not_open_a_document(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->administrator())
            ->getJson(route('lms.handbooks.progress', $document))
            ->assertNotFound();
    }

    /* ---------- Кому это видно ---------- */

    /**
     * Смотрит администратор и суперадминистратор, и только они (решение
     * пользователя 2026-09-12). Права на правку материала для этого мало: речь
     * о том, кто как учится, а не о том, что написано в уроке.
     */
    public function test_the_report_answers_to_an_administrator_alone(): void
    {
        [$course, $lessons] = $this->courseWithLessons(1);
        $document = Regulation::factory()->published()->create();

        foreach ([$this->author(), $this->learner()] as $person) {
            $this->actingAs($person)
                ->getJson(route('lms.courses.progress', $course))
                ->assertForbidden();

            $this->actingAs($person)
                ->getJson(route('lms.lessons.progress', $lessons->first()))
                ->assertForbidden();

            $this->actingAs($person)
                ->getJson(route('lms.documents.progress', $document))
                ->assertForbidden();
        }

        $this->actingAs($this->superAdministrator())
            ->getJson(route('lms.courses.progress', $course))
            ->assertOk();
    }

    /**
     * Разбор теста переехал к той же черте: прежде он отвечал праву на правку
     * урока и стоял в редакторе, а это такая же статистика прохождения.
     */
    public function test_the_quiz_breakdown_moved_behind_the_same_door(): void
    {
        [, $lessons] = $this->courseWithLessons(1);
        Quiz::factory()->withQuestions(1)->forLesson($lessons->first())->create();

        $document = Regulation::factory()->published()->create();
        Quiz::factory()->withQuestions(1)->forRegulation($document)->create();

        $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $lessons->first()))
            ->assertForbidden();

        $this->actingAs($this->author())
            ->getJson(route('lms.documents.quiz.statistics', $document))
            ->assertForbidden();

        $this->actingAs($this->administrator())
            ->getJson(route('lms.quiz.statistics', $lessons->first()))
            ->assertOk()
            ->assertJsonPath('data.attempts', 0);
    }

    /* ---------- Заготовки ---------- */

    /**
     * @return array{0: Course, 1: Collection<int, Lesson>}
     */
    private function courseWithLessons(int $count): array
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lessons = Lesson::factory()->count($count)->create(['module_id' => $module->id]);

        return [$course, $lessons];
    }

    private function plan(User $learner, string $type, int $id): void
    {
        LearningPlanItem::query()->create([
            'user_id' => $learner->id,
            'plannable_type' => $type,
            'plannable_id' => $id,
            'position' => 1,
        ]);
    }

    private function attempt(Quiz $quiz, User $learner, int $score, bool $passed, mixed $at = null): void
    {
        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $learner->id,
            'score' => $score,
            'passed' => $passed,
            'answers' => [],
            'completed_at' => $at ?? now(),
        ]);
    }
}
