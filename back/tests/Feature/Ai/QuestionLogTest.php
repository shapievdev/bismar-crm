<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\ConsultantOutcome;
use App\Enums\Permission;
use App\Models\ConsultantQuestion;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Журнал вопросов.
 *
 * Он существует ради одного различения: материала нет — или материал есть, а
 * модель им не воспользовалась. Первое чинит автор курса, второе — настройка
 * модели, и перепутать их значит месяц дописывать уроки, которые и так были.
 */
final class QuestionLogTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_answered_question_is_recorded_with_its_sources(): void
    {
        $this->publishedLesson('Разбор возражений', 'Клиент говорит «дорого» — выслушайте и уточните.');
        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($asker = $this->learner())
            ->postJson(route('lms.ask'), ['question' => 'что делать при возражении дорого'])
            ->assertOk();

        $record = ConsultantQuestion::query()->sole();

        $this->assertSame(ConsultantOutcome::Answered, $record->outcome);
        $this->assertSame($asker->getKey(), $record->user_id);
        $this->assertSame(1, $record->cited);
        $this->assertGreaterThan(0, $record->retrieved);
    }

    /** Поиск не нашёл ничего — дыра в материале. */
    public function test_a_question_with_no_material_is_recorded_as_such(): void
    {
        $this->publishedLesson('Разбор возражений', 'Клиент говорит «дорого» — выслушайте.');
        $this->fakeModel(FakeAnthropicTransport::replying('не должно быть вызвано'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'как поменять картридж в принтере'])
            ->assertOk();

        $record = ConsultantQuestion::query()->sole();

        $this->assertSame(ConsultantOutcome::NothingFound, $record->outcome);
        $this->assertSame(0, $record->retrieved);
    }

    /**
     * Фрагменты модели передали, а она ответила «ничего нет». Дописывать урок
     * тут бесполезно — он уже есть.
     */
    public function test_material_the_model_ignored_is_recorded_apart(): void
    {
        $this->publishedLesson('Разбор возражений', 'Клиент говорит «дорого» — выслушайте и уточните.');
        $this->fakeModel(FakeAnthropicTransport::replying('В базе знаний об этом ничего нет.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'что делать при возражении дорого'])
            ->assertOk();

        $record = ConsultantQuestion::query()->sole();

        $this->assertSame(ConsultantOutcome::Unused, $record->outcome);
        $this->assertGreaterThan(0, $record->retrieved);
        $this->assertSame(0, $record->cited);
    }

    public function test_a_model_failure_is_recorded(): void
    {
        $this->publishedLesson('Разбор возражений', 'Клиент говорит «дорого» — выслушайте.');
        $this->fakeModel(FakeAnthropicTransport::unreachable());

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'что делать при возражении дорого'])
            ->assertServiceUnavailable();

        $this->assertSame(ConsultantOutcome::Failed, ConsultantQuestion::query()->sole()->outcome);
    }

    /**
     * Журнал вспомогательный: сломанная запись не должна отнимать у сотрудника
     * ответ, который уже получен.
     */
    public function test_a_broken_journal_does_not_break_the_answer(): void
    {
        $this->publishedLesson('Разбор возражений', 'Клиент говорит «дорого» — выслушайте и уточните.');
        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        Schema::drop('consultant_questions');

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'что делать при возражении дорого'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources');
    }

    public function test_the_log_lists_the_unanswered_first_of_all(): void
    {
        ConsultantQuestion::query()->create([
            'question' => 'как оформить отпуск',
            'outcome' => ConsultantOutcome::NothingFound,
        ]);
        ConsultantQuestion::query()->create([
            'question' => 'что делать при возражении',
            'outcome' => ConsultantOutcome::Answered,
            'cited' => 2,
        ]);

        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index', ['unanswered' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'как оформить отпуск')
            ->assertJsonPath('data.0.outcome_label', 'Материал не найден');
    }

    public function test_a_reader_without_authoring_rights_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('ai.questions.index'))
            ->assertForbidden();
    }

    /* ---------- helpers ---------- */

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }

    private function publishedLesson(string $title, string $content): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => $title,
            'content' => $content,
        ]);
    }
}
