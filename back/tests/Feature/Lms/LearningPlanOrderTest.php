<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\MaterialKind;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Очередь плана обучения: пока план не пройден, сотруднику открыт ровно один
 * материал — тот, до которого дошла очередь.
 *
 * Запрет накрывает всю базу знаний — курсы, документы и справочники. Открытым
 * остаётся ещё и всё, что человек уже прошёл: очередь не даёт забегать вперёд,
 * а не отбирает прочитанное.
 */
final class LearningPlanOrderTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Назначение плана шлёт уведомление; здесь проверяют не его.
        Queue::fake();
    }

    /**
     * @param  list<Course|Regulation>  $items
     */
    private function assign(User $learner, array $items): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('lms.plans.update', $learner), [
                'items' => array_map(static fn (Course|Regulation $item): array => [
                    'type' => $item instanceof Course ? 'course' : $item->kind->value,
                    'id' => $item->id,
                ], $items),
            ])
            ->assertOk();
    }

    /** Проходит курс целиком — так, как это делает сам сотрудник. */
    private function finish(User $learner, Course $course): void
    {
        foreach ($course->lessons()->get() as $lesson) {
            $this->actingAs($learner)
                ->postJson(route('lms.lessons.complete', $lesson))
                ->assertOk();
        }
    }

    private function open(User $learner, Course $course): TestResponse
    {
        return $this->actingAs($learner)->getJson(route('lms.courses.show', $course));
    }

    /* ---------- Очередь ---------- */

    /**
     * Посторонний курс закрыт, пока в плане есть незакрытый шаг: план на то и
     * план, что его проходят раньше, чем выбирают по интересу.
     */
    public function test_a_course_outside_the_plan_stays_shut_until_the_plan_is_done(): void
    {
        $learner = $this->learner();
        $assigned = Course::factory()->withLessons(1)->create(['title' => 'Основы']);
        $other = Course::factory()->published()->create();

        $this->assign($learner, [$assigned]);

        $this->open($learner, $other)
            ->assertForbidden()
            ->assertJsonPath('message', 'Курс откроется, когда вы завершите план обучения. Сейчас ваш шаг — пройдите курс «Основы».');

        $this->open($learner, $assigned)->assertOk();
    }

    /**
     * Шаги открываются по одному: второй курс ждёт, пока пройден первый.
     */
    public function test_the_plan_opens_one_step_at_a_time(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->withLessons(1)->create()->all();

        $this->assign($learner, [$first, $second]);

        $this->open($learner, $second)->assertForbidden();

        $this->finish($learner, $first);

        $this->open($learner, $second)->assertOk();
    }

    /**
     * Пройденный план больше ничего не держит.
     */
    public function test_a_finished_plan_opens_the_catalogue(): void
    {
        $learner = $this->learner();
        $assigned = Course::factory()->withLessons(1)->create();
        $other = Course::factory()->published()->create();

        $this->assign($learner, [$assigned]);
        $this->finish($learner, $assigned);

        $this->open($learner, $other)->assertOk();
    }

    /**
     * За пройденным курсом возвращаются перечитать — и разбор своей попытки
     * теста лежит там же. Забирать его, стоило перейти к следующему шагу,
     * значит запирать собственную учёбу.
     */
    public function test_a_course_already_finished_stays_open(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->withLessons(1)->create()->all();

        $this->assign($learner, [$first, $second]);
        $this->finish($learner, $first);

        $this->open($learner, $first)->assertOk();
    }

    /**
     * Урок закрытого курса закрыт вместе с ним: иначе очередь обходилась бы
     * ссылкой на первый же урок.
     */
    public function test_a_locked_course_does_not_hand_out_its_lessons(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->withLessons(1)->create()->all();

        $this->assign($learner, [$first, $second]);

        $this->actingAs($learner)
            ->getJson(route('lms.lessons.show', $second->lessons()->firstOrFail()))
            ->assertForbidden();
    }

    /**
     * План без плана ничего не запрещает: у большинства сотрудников его нет
     * вовсе, и «нечего проходить» не должно читаться как «нельзя ничего».
     */
    public function test_a_learner_without_a_plan_browses_freely(): void
    {
        $this->open($this->learner(), Course::factory()->published()->create())->assertOk();
    }

    /* ---------- Документы ---------- */

    /**
     * Документ в плане держит очередь наравне с курсом.
     */
    public function test_a_document_step_holds_the_queue(): void
    {
        $learner = $this->learner();
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $course = Course::factory()->withLessons(1)->create();

        $this->assign($learner, [$document, $course]);

        $this->open($learner, $course)
            ->assertForbidden()
            ->assertJsonPath('message', 'Курс откроется, когда вы завершите план обучения. Сейчас ваш шаг — прочитайте «Кассовая дисциплина».');

        $this->actingAs($learner)
            ->postJson(route('lms.documents.acknowledge', $document))
            ->assertOk();

        $this->open($learner, $course)->assertOk();
    }

    /**
     * Документ вне плана закрыт наравне с курсом — иначе очередь обходилась бы
     * тем же содержанием, выложенным правилом.
     */
    public function test_a_document_outside_the_plan_stays_shut(): void
    {
        $learner = $this->learner();
        $this->assign($learner, [Course::factory()->withLessons(1)->create(['title' => 'Основы'])]);

        $this->actingAs($learner)
            ->getJson(route('lms.documents.show', Regulation::factory()->published()->create()))
            ->assertForbidden()
            ->assertJsonPath('message', 'Документ откроется, когда вы завершите план обучения. Сейчас ваш шаг — пройдите курс «Основы».');
    }

    /**
     * Справочник — свой раздел, и отказ называет его своим именем: «курс
     * закрыт» на странице справочника читалось бы ошибкой.
     */
    public function test_a_handbook_outside_the_plan_stays_shut(): void
    {
        $learner = $this->learner();
        $this->assign($learner, [Course::factory()->withLessons(1)->create(['title' => 'Основы'])]);

        $handbook = Regulation::factory()->published()->create(['kind' => MaterialKind::Handbook]);

        $this->actingAs($learner)
            ->getJson(route('lms.handbooks.show', $handbook))
            ->assertForbidden()
            ->assertJsonPath('message', 'Справочник откроется, когда вы завершите план обучения. Сейчас ваш шаг — пройдите курс «Основы».');
    }

    /**
     * Прочитанное однажды остаётся открытым: под правилом расписались, и
     * показывать его обязаны по первому требованию.
     */
    public function test_a_document_already_read_stays_open(): void
    {
        $learner = $this->learner();
        $document = Regulation::factory()->published()->create();
        $course = Course::factory()->withLessons(1)->create();

        $this->assign($learner, [$document, $course]);

        $this->actingAs($learner)
            ->postJson(route('lms.documents.acknowledge', $document))
            ->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk();
    }

    /**
     * Проверку, приложенную к документу уже после отметки, всё равно надо
     * сдать.
     *
     * Обычно спорить не о чем: у документа с проверкой кнопки «ознакомлен» нет
     * вовсе, отметку ставит сдача (см. GradeQuizAttempt). Но правило компании
     * меняют — вчерашний документ сегодня выходит с тестом, — и старая отметка
     * не должна открывать план тому, кто нового теста не видел.
     */
    public function test_a_quiz_added_later_puts_the_document_step_back_in_the_queue(): void
    {
        $learner = $this->learner();
        $document = Regulation::factory()->published()->create();
        $course = Course::factory()->withLessons(1)->create();

        $this->assign($learner, [$document, $course]);

        $this->actingAs($learner)
            ->postJson(route('lms.documents.acknowledge', $document))
            ->assertOk();

        $this->open($learner, $course)->assertOk();

        $quiz = Quiz::query()->create([
            'quizzable_type' => $document->getMorphClass(),
            'quizzable_id' => $document->id,
            'title' => 'Проверка',
            'passing_score' => 80,
        ]);
        QuizQuestion::query()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Сколько?',
            'type' => 'single',
            'points' => 1,
            'position' => 1,
        ]);

        $this->open($learner, $course)->assertForbidden();

        QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $learner->id,
            'score' => 100,
            'passed' => true,
            'answers' => [],
            'completed_at' => now(),
        ]);

        $this->open($learner, $course)->assertOk();
    }

    /* ---------- Кого очередь не касается ---------- */

    public function test_an_administrator_ignores_the_plan(): void
    {
        $administrator = $this->administrator();
        $this->assign($administrator, [Course::factory()->withLessons(1)->create()]);

        $this->open($administrator, Course::factory()->published()->create())->assertOk();
    }

    /**
     * Редактор иначе не открыл бы курс, который сам же и пишет.
     */
    public function test_an_editor_ignores_the_plan(): void
    {
        $editor = $this->editor();
        $this->assign($editor, [Course::factory()->withLessons(1)->create()]);

        $this->open($editor, Course::factory()->published()->create())->assertOk();
    }

    /* ---------- Что видно в каталоге ---------- */

    /**
     * Закрытый курс из каталога не пропадает: он есть, он виден, и человек
     * должен понимать, что откроют его после плана, а не никогда.
     */
    public function test_the_catalogue_marks_locked_courses(): void
    {
        $learner = $this->learner();
        $assigned = Course::factory()->withLessons(1)->create(['title' => 'Основы']);
        $other = Course::factory()->published()->create(['title' => 'Прочее']);

        $this->assign($learner, [$assigned]);

        $catalogue = collect(
            $this->actingAs($learner)
                ->getJson(route('lms.courses.index'))
                ->assertOk()
                ->json('data'),
        )->keyBy('title');

        $this->assertFalse($catalogue['Основы']['is_locked']);
        $this->assertTrue($catalogue['Прочее']['is_locked']);
    }

    /**
     * Курс, начатый до того, как назначили план, ждёт своей очереди наравне с
     * остальными — и «Мои курсы» говорят об этом строкой, а не ссылкой в отказ.
     */
    public function test_my_courses_marks_a_course_that_waits_its_turn(): void
    {
        $learner = $this->learner();
        $started = Course::factory()->withLessons(1)->create();

        $this->actingAs($learner)
            ->postJson(route('lms.enroll', $started))
            ->assertCreated();

        $this->assign($learner, [Course::factory()->withLessons(1)->create()]);

        $this->actingAs($learner)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonPath('data.0.course.is_locked', true);
    }

    /**
     * Каталог документов запирается тем же правилом, что и каталог курсов.
     */
    public function test_the_document_catalogue_marks_locked_material(): void
    {
        $learner = $this->learner();
        $document = Regulation::factory()->published()->create(['title' => 'Первое']);
        $other = Regulation::factory()->published()->create(['title' => 'Прочее']);

        $this->assign($learner, [$document]);

        $catalogue = collect(
            $this->actingAs($learner)
                ->getJson(route('lms.documents.index'))
                ->assertOk()
                ->json('data'),
        )->keyBy('title');

        $this->assertFalse($catalogue['Первое']['is_locked']);
        $this->assertTrue($catalogue['Прочее']['is_locked']);
    }

    /**
     * В своём плане видно, какой шаг сейчас, а какие ещё заперты, — и шаг с
     * документом запирается наравне с курсом.
     */
    public function test_the_plan_says_which_steps_are_locked(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->withLessons(1)->create()->all();
        $document = Regulation::factory()->published()->create();

        $this->assign($learner, [$first, $second, $document]);

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.is_locked', false)
            ->assertJsonPath('data.1.is_locked', true)
            ->assertJsonPath('data.2.is_locked', true);
    }

    /**
     * Чужой закрытый курс по-прежнему отвечает «не найдено», а не «сначала
     * пройдите план»: ответ про очередь рассказал бы, что курс существует.
     */
    public function test_a_private_course_still_answers_not_found(): void
    {
        $learner = $this->learner();
        $this->assign($learner, [Course::factory()->withLessons(1)->create()]);

        $this->open($learner, Course::factory()->published()->closed()->create())->assertNotFound();
    }
}
