<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\Permission;
use App\Models\ConsultantQuestion;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * The consultant answers from the company's own material and nothing else.
 *
 * The model is never really called: the transport underneath the SDK is
 * replaced, which lets each test assert on the request that would have gone
 * over the wire and hand back a fixed reply.
 */
final class ConsultantTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * The point of the whole feature: with nothing to ground an answer in, the
     * model is not asked at all. A question about material that does not exist
     * therefore cannot come back as an invented regulation.
     */
    public function test_a_question_with_no_matching_material_never_reaches_the_model(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Клиент говорит «дорого» — сначала выслушайте.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как поменять картридж в принтере бухгалтерии'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В материалах базы знаний об этом ничего нет.')
            ->assertJsonPath('data.sources', []);

        $this->assertFalse($transport->wasCalled(), 'Модель спросили, хотя материалов не нашлось.');
    }

    public function test_an_answer_cites_the_lesson_it_was_built_from(): void
    {
        $lesson = $this->publishedLesson(
            'Работа с возражениями',
            'Когда клиент говорит «дорого», выслушайте и уточните, с чем он сравнивает.',
        );

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте и уточните [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.lesson_id', $lesson->id)
            ->assertJsonPath('data.sources.0.lesson_title', 'Работа с возражениями')
            // Само место, а не только название урока: урок бывает длинным, и
            // проверять утверждение по нему целиком читатель не станет.
            ->assertJsonPath(
                'data.sources.0.quote',
                'Когда клиент говорит «дорого», выслушайте и уточните, с чем он сравнивает.',
            );
    }

    /**
     * Unpublished material is invisible to the consultant. Paraphrasing a draft
     * leaks it exactly as surely as showing the page would.
     */
    public function test_a_draft_course_is_never_sent_to_the_model(): void
    {
        $this->publishedLesson('Возражения клиентов', 'Опубликованный разбор возражений.');
        $this->lessonIn(
            Course::factory()->create(['title' => 'Черновик']),
            'Секретный черновик',
            'Черновой разбор возражений клиентов.',
        );

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Ответ.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $sent = json_encode($transport->payload(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Секретный черновик', (string) $sent);
        $this->assertStringContainsString('Возражения клиентов', (string) $sent);
    }

    /**
     * People ask in whole sentences, and the filler words carry no subject.
     *
     * Without this the question below pulls in every lesson called "Что делать
     * дальше" in the base — they share the word "делать" and nothing else, and
     * they crowd the one lesson that answers out of the excerpt budget while
     * being charged for.
     */
    public function test_a_lesson_sharing_only_a_filler_word_is_not_sent(): void
    {
        $course = Course::factory()->published()->create();

        $this->lessonIn($course, 'Разбор возражений', 'Когда клиент говорит «дорого», выслушайте и уточните.');
        $this->lessonIn($course, 'Что делать дальше', 'Отметьте урок пройденным и переходите к следующему.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Ответ.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что делать если клиент говорит дорого'])
            ->assertOk();

        $sent = json_encode($transport->payload()['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Разбор возражений', (string) $sent);
        $this->assertStringNotContainsString('Что делать дальше', (string) $sent);
    }

    /**
     * A citation of something that was never provided must not survive into the
     * response — otherwise the interface would offer a link to material the
     * answer had no access to, and the reader would take an unsupported claim
     * for a sourced one.
     */
    public function test_a_citation_of_material_that_was_not_provided_is_dropped(): void
    {
        $lesson = $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');

        $this->fakeModel(FakeAnthropicTransport::replying(
            'Так написано [источник 1], а также [источник 7].',
        ));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.lesson_id', $lesson->id)
            ->assertJsonPath('data.answer', 'Так написано [источник 1], а также.');
    }

    /**
     * The reason lessons are searched in pieces at all.
     *
     * A pasted page runs to thousands of characters; sent whole it is cut at
     * the excerpt limit, and everything past the cut may as well not exist.
     * Asked about the last section of such a lesson, the consultant used to
     * answer that there was nothing on the subject.
     */
    public function test_the_end_of_a_long_lesson_is_reachable(): void
    {
        $course = Course::factory()->published()->create();

        $filler = trim(str_repeat('Обычный текст про подготовку основания и грунтование стен. ', 120));
        $this->lessonIn($course, 'Полный материал', $filler."\n\nУглы и примыкания красят кистью.");

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Кистью [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Чем красить углы и примыкания'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources');

        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringContainsString('Углы и примыкания красят кистью.', $sent);
    }

    /**
     * Asked for one number per marker, the model still groups them.
     *
     * Left unparsed, «[источник 1, 2]» stays in the answer as literal text and
     * the reader gets a bracketed number that links to nothing.
     */
    public function test_a_grouped_citation_becomes_one_marker_per_source(): void
    {
        $course = Course::factory()->published()->create();

        $this->lessonIn($course, 'Разбор возражений', 'Возражение по цене — самое частое.');
        $this->lessonIn($course, 'Работа с ценой', 'Скидка при возражении по цене согласуется с руководителем.');

        $this->fakeModel(FakeAnthropicTransport::replying('Так написано [источник 1, 2].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражение по цене'])
            ->assertOk()
            ->assertJsonCount(2, 'data.sources')
            ->assertJsonPath('data.answer', 'Так написано [источник 1][источник 2].');
    }

    /**
     * The numbers in the text and the list underneath it have to agree, and the
     * model numbers by what it was shown — not by what survives the check.
     */
    public function test_surviving_citations_are_renumbered_from_one(): void
    {
        $course = Course::factory()->published()->create();

        $this->lessonIn($course, 'Разбор возражений', 'Возражение по цене — самое частое.');
        $this->lessonIn($course, 'Работа с ценой', 'Скидка при возражении по цене согласуется с руководителем.');

        // Only the second fragment is cited, so the one reference that survives
        // has to come back numbered one — whichever lesson the search happened
        // to put in second place.
        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Согласует руководитель [источник 2].'));

        $response = $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражение по цене'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.answer', 'Согласует руководитель [источник 1].');

        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringContainsString(
            '[источник 2] Курс «'.$course->title.'» → урок «'.$response->json('data.sources.0.lesson_title').'»',
            $sent,
        );
    }

    /**
     * The rules and the catalogue are identical for every reader and every
     * question, so they sit behind a cache breakpoint. If that breakpoint is
     * ever dropped the feature still works — it just quietly costs ten times
     * more, which is the kind of regression only a test catches.
     */
    public function test_the_rules_and_catalogue_are_sent_behind_a_cache_breakpoint(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Работа с клиентами']);
        $this->lessonIn($course, 'Работа с возражениями', 'Выслушайте клиента.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Ответ.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения'])
            ->assertOk();

        $system = $transport->payload()['system'];

        $this->assertSame('ephemeral', $system[0]['cache_control']['type'] ?? null);

        // Что именно кешируется, а не как оно сформулировано: указания
        // переписывают под каждую новую модель, и тест, придирающийся к их
        // словам, ломается на каждой такой правке, ничего при этом не защищая.
        // Защищать надо одно — что за breakpoint попали и указания, и перечень.
        $this->assertStringContainsString('МАТЕРИАЛЫ БАЗЫ ЗНАНИЙ:', $system[0]['text']);
        $this->assertStringContainsString('Работа с клиентами', $system[0]['text']);
        $this->assertStringContainsString('[источник 1]', $system[0]['text']);
    }

    public function test_a_model_failure_is_not_leaked_to_the_reader(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');

        $this->fakeModel(FakeAnthropicTransport::unreachable());

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Консультант сейчас недоступен. Попробуйте позже.');
    }

    /**
     * Переписка возвращается той же формой, что и свежий ответ: страница чата
     * не должна знать, приехал он только что или лежал в истории.
     */
    public function test_a_reader_gets_their_own_conversation_back(): void
    {
        $lesson = $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');
        $reader = $this->learner();

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'возражения клиентов')
            ->assertJsonPath('data.0.answer.answer', 'Выслушайте [источник 1].')
            // Ссылки хранятся снимком: без них ответ нечем проверить, а ради
            // этого они и ставились.
            ->assertJsonPath('data.0.answer.sources.0.lesson_id', $lesson->id);
    }

    /** Чужие вопросы — чужое дело: отбор идёт по спрашивавшему. */
    public function test_one_reader_never_sees_another_readers_conversation(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'совершенно чужой вопрос'])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * На месте сбоя в журнале лежит сообщение поставщика. Оно для того, кто
     * чинит, а не для того, кто спрашивал: ему про недоступность консультанта
     * сказали ещё тогда.
     */
    public function test_a_model_failure_never_becomes_a_message_in_the_history(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');

        $reader = $this->learner();

        $this->fakeModel(FakeAnthropicTransport::unreachable());

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'возражения'])
            ->assertServiceUnavailable();

        $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Очистка убирает переписку с глаз сотрудника, но не из журнала: для
     * автора курса это перечень того, чего в базе знаний не хватает, и терять
     * его по чужой просьбе нельзя.
     */
    public function test_clearing_the_conversation_keeps_the_journal_intact(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Выслушайте клиента.');
        $reader = $this->learner();

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $this->actingAs($reader)
            ->deleteJson(route('lms.ask.forget'))
            ->assertNoContent();

        $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(1, ConsultantQuestion::query()->count(), 'Запись пропала из журнала.');

        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_reader_without_course_access_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->postJson(route('lms.ask'), ['question' => 'возражения'])
            ->assertForbidden();
    }

    public function test_a_question_too_short_to_search_on_is_rejected(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => ' '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }

    /* ---------- helpers ---------- */

    /**
     * Puts the fake transport under a real SDK client, so the request is built
     * and serialised by the SDK exactly as it would be in production.
     */
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
        return $this->lessonIn(Course::factory()->published()->create(), $title, $content);
    }

    private function lessonIn(Course $course, string $title, string $content): Lesson
    {
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => $title,
            'content' => $content,
        ]);
    }
}
