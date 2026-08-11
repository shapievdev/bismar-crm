<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\AnswerFeedback;
use App\Enums\AnswerPath;
use App\Enums\ConsultantOutcome;
use App\Models\ConsultantQuestion;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Круг «спросил — не помогло — дописали — узнал», замкнутый до конца.
 *
 * Прежде он обрывался на первом шаге. Журнал считает удачей всякий ответ со
 * ссылками, даже когда сослался он не на то, и знает об этом только тот, кто
 * спрашивал; сказать ему было нечем, а автору — нечего чинить. Теперь сотрудник
 * говорит, помогло ли, просит дописать, а дописанное возвращается в тот же
 * разговор и в ту же базу знаний.
 */
final class AnswerFeedbackTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Полученный ответ можно оценить сразу, не перезагружая переписку.
     *
     * Для этого сам ответ и несёт номер строки журнала: без него оценить можно
     * было бы только то, что уже пришло историей, — то есть ничего из
     * сказанного в текущем разговоре.
     */
    public function test_a_fresh_answer_carries_the_journal_row_it_can_be_rated_by(): void
    {
        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');
        $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $id = $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет фасадная краска?'])
            ->assertOk()
            ->json('data.id');

        $this->assertNotNull($id, 'Ответ пришёл без строки журнала — оценить его нечем.');

        $this->actingAs($reader)
            ->postJson(route('lms.ask.feedback', $id), ['helpful' => true])
            ->assertOk()
            ->assertJsonPath('data.feedback', AnswerFeedback::Helpful->value);
    }

    /** Ответ помог — записали, и на этом всё: ни заявки, ни работы автору. */
    public function test_a_helpful_answer_is_recorded_and_asks_for_nothing(): void
    {
        $reader = $this->learner();
        $question = $this->asked($reader);

        $this->actingAs($reader)
            ->postJson(route('lms.ask.feedback', $question), ['helpful' => true])
            ->assertOk()
            ->assertJsonPath('data.feedback', AnswerFeedback::Helpful->value)
            ->assertJsonPath('data.requested', false);

        $question->refresh();

        $this->assertSame(AnswerFeedback::Helpful, $question->feedback);
        $this->assertNull($question->requested_at);
    }

    /** Ответ не помог — оценка сама по себе, заявка отдельным шагом. */
    public function test_an_unhelpful_answer_can_be_turned_into_a_request(): void
    {
        $reader = $this->learner();
        $question = $this->asked($reader);

        $this->actingAs($reader)
            ->postJson(route('lms.ask.feedback', $question), ['helpful' => false])
            ->assertOk()
            ->assertJsonPath('data.requested', false);

        $this->assertNull($question->refresh()->requested_at);

        $this->actingAs($reader)
            ->postJson(route('lms.ask.request', $question), ['note' => 'Про фасадную, а не про интерьерную.'])
            ->assertOk()
            ->assertJsonPath('data.requested', true);

        $question->refresh();

        $this->assertNotNull($question->requested_at);
        $this->assertSame('Про фасадную, а не про интерьерную.', $question->request_note);
        $this->assertSame(AnswerFeedback::Unhelpful, $question->feedback);
    }

    /** Пояснение к заявке необязательно: чаще всего человеку нечего добавить. */
    public function test_a_request_needs_no_explanation(): void
    {
        $reader = $this->learner();
        $question = $this->asked($reader);

        $this->actingAs($reader)
            ->postJson(route('lms.ask.request', $question))
            ->assertOk()
            ->assertJsonPath('data.requested', true);

        $this->assertNotNull($question->refresh()->requested_at);
    }

    /** Оценка — слова о своём разговоре, и за другого их не поставить. */
    public function test_someone_elses_answer_cannot_be_rated(): void
    {
        $question = $this->asked($this->learner());

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask.feedback', $question), ['helpful' => false])
            ->assertForbidden();

        $this->assertNull($question->refresh()->feedback);
    }

    /**
     * Заявки видно в журнале отдельно от прочего.
     *
     * За ними стоит живой человек, оставшийся без ответа, — этим они и
     * отличаются от догадок журнала о пробелах.
     */
    public function test_requests_are_listed_apart_in_the_journal(): void
    {
        $reader = $this->learner();

        $this->asked($reader, 'Вопрос без заявки');
        $requested = $this->asked($reader, 'Вопрос с заявкой');

        $this->actingAs($reader)->postJson(route('lms.ask.request', $requested))->assertOk();

        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index', ['requested' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Вопрос с заявкой')
            // Счётчик заявок — без срока давности: просьба ждёт ответа, пока на
            // неё не ответят, сколько бы недель ни прошло.
            ->assertJsonPath('meta.summary.requests', 1);
    }

    /**
     * Ответ автора: строкой в урок — и обратно в разговор, где вопрос задали.
     *
     * Ради этого журнал и заводился. Прежде путь обратно к материалу автор
     * проходил руками, а сотрудник о дописанном не узнавал вовсе.
     */
    public function test_an_author_answers_the_question_into_a_lesson(): void
    {
        $reader = $this->learner();
        $lesson = $this->publishedLesson('Покраска стен', 'Текст урока.');
        $question = $this->asked($reader, 'Сколько сохнет фасадная краска?');

        $this->actingAs($reader)->postJson(route('lms.ask.request', $question))->assertOk();

        $this->actingAs($this->author())
            ->postJson(route('ai.questions.resolve', $question), [
                'lesson_id' => $lesson->id,
                'question' => 'Сколько сохнет фасадная краска?',
                'answer' => 'Не менее четырёх часов при 20 °C.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'Не менее четырёх часов при 20 °C.')
            ->assertJsonPath('data.resolution_lesson.title', 'Покраска стен');

        // Строка в таблице урока — чтобы следующий, кто спросит о том же,
        // получил ответ от самого консультанта.
        $row = $lesson->answers()->sole();

        $this->assertSame('Сколько сохнет фасадная краска?', $row->question);
        $this->assertSame('Не менее четырёх часов при 20 °C.', $row->answer);

        // И сообщение в переписке того, кто спрашивал.
        $exchange = $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Не менее четырёх часов при 20 °C.', $exchange['resolution']['answer']);
        $this->assertSame('Покраска стен', $exchange['resolution']['lesson']['lesson_title']);
        $this->assertTrue($exchange['resolution']['is_new'], 'Дополнение не отмечено новым.');

        // Прочитанное перестаёт быть новостью.
        $again = $this->actingAs($reader)->getJson(route('lms.ask.history'))->json('data.0');

        $this->assertFalse($again['resolution']['is_new']);
    }

    /**
     * Дописанный ответ становится частью базы знаний, а не только сообщением.
     *
     * Тот же вопрос, заданный следующим сотрудником, отвечается уже строкой
     * таблицы — тем, что автор написал руками.
     */
    public function test_the_written_answer_is_what_the_consultant_says_next_time(): void
    {
        $reader = $this->learner();
        $lesson = $this->publishedLesson('Покраска стен', 'Текст урока.');
        $question = $this->asked($reader, 'Сколько сохнет фасадная краска?');

        $this->actingAs($this->author())
            ->postJson(route('ai.questions.resolve', $question), [
                'lesson_id' => $lesson->id,
                'question' => 'Сколько сохнет фасадная краска?',
                'answer' => 'Не менее четырёх часов при 20 °C.',
            ])
            ->assertOk();

        $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет фасадная краска?'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.question', 'Сколько сохнет фасадная краска?')
            ->assertJsonPath('data.sources.0.quote', 'Не менее четырёх часов при 20 °C.');

        $this->assertSame(
            AnswerPath::Curated,
            ConsultantQuestion::query()->latest('id')->first()?->answered_from,
        );
    }

    /** В чужой закрытый курс ответ не занести, даже видя вопрос в журнале. */
    public function test_an_answer_cannot_be_written_into_a_course_that_is_closed_to_the_author(): void
    {
        $closed = Course::factory()->published()->closed()->create();
        $module = CourseModule::factory()->create(['course_id' => $closed->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id, 'title' => 'Закрытый урок']);

        $question = $this->asked($this->learner());

        $this->actingAs($this->author())
            ->postJson(route('ai.questions.resolve', $question), [
                'lesson_id' => $lesson->id,
                'question' => 'Вопрос',
                'answer' => 'Ответ',
            ])
            ->assertForbidden();

        $this->assertSame(0, $lesson->answers()->count());
    }

    /**
     * Удаление вопроса из журнала убирает его и из переписки.
     *
     * Строка одна на двоих: держать её ради чата, признав мусором, значило бы
     * оставить сотруднику ответ, который автор счёл ненужным.
     */
    public function test_deleting_a_question_removes_it_from_the_conversation_too(): void
    {
        $reader = $this->learner();
        $question = $this->asked($reader);

        $this->actingAs($this->author())
            ->deleteJson(route('ai.questions.destroy', $question))
            ->assertNoContent();

        $this->assertSame(0, ConsultantQuestion::query()->count());

        $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Журнал закрыт для тех, кто материал не пишет: удалять — тем более. */
    public function test_a_reader_cannot_delete_journal_entries(): void
    {
        $reader = $this->learner();
        $question = $this->asked($reader);

        $this->actingAs($reader)
            ->deleteJson(route('ai.questions.destroy', $question))
            ->assertForbidden();

        $this->assertSame(1, ConsultantQuestion::query()->count());
    }

    /* ---------- helpers ---------- */

    /** Уже заданный вопрос: так его хранит журнал. */
    private function asked(User $reader, string $question = 'Сколько сохнет краска?'): ConsultantQuestion
    {
        return ConsultantQuestion::query()->create([
            'user_id' => $reader->getKey(),
            'question' => $question,
            'answer' => 'Ответ консультанта.',
            'outcome' => ConsultantOutcome::Answered,
            'answered_from' => AnswerPath::Passages,
        ]);
    }

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
