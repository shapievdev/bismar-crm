<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
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
 * Консультант помнит разговор — у каждого сотрудника свой.
 *
 * Без памяти каждый вопрос читался как первый, и разговор рассыпался через
 * сообщение: «а сколько это сохнет?» не значило ничего ни для поиска, где в нём
 * нет ни одного слова о предмете, ни для модели, которая не видела, о чём шла
 * речь. Память чинит и то и другое: вопрос достраивается разговором перед
 * поиском, а сам разговор уходит модели репликами.
 */
final class ConversationMemoryTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Продолжение разговора ищется по тому, о чём шла речь.
     *
     * Ради этого всё и затевалось. В самом вопросе слов о краске нет, и поиск
     * по нему — заведомо пустой; ищут по достроенному.
     */
    public function test_a_follow_up_question_is_searched_by_what_the_talk_was_about(): void
    {
        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');
        $this->remember($reader, 'Чем разбавляют фасадную краску?', 'Водой, не более 10 % объёма.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replyingInTurn(
            'Сколько сохнет фасадная краска?',
            'Не менее четырёх часов [источник 1].',
        ));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'А сколько она сохнет?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.lesson_title', 'Покраска стен');

        // Два обращения к модели: сперва она достраивает вопрос, потом отвечает.
        $this->assertSame(2, $transport->calls());

        [$restating, $answering] = $transport->payloads();

        $this->assertStringContainsString(
            'Чем разбавляют фасадную краску?',
            (string) $restating['messages'][0]['content'],
        );

        $this->assertStringContainsString(
            'Фасадная краска сохнет не менее четырёх часов.',
            json_encode($answering['messages'], JSON_UNESCAPED_UNICODE) ?: '',
        );

        // В журнале — слова сотрудника, а рядом с ними то, чем на самом деле
        // искали: иначе разбирающий не поймёт, откуда взялись источники.
        $logged = ConsultantQuestion::query()->latest('id')->first();

        $this->assertSame('А сколько она сохнет?', $logged?->question);
        $this->assertSame('Сколько сохнет фасадная краска?', $logged?->searched_as);
    }

    /**
     * Разговор уходит модели репликами, а не пересказом в одном сообщении.
     *
     * Роли для того и заведены, чтобы модель отличала свои прошлые слова от
     * слов сотрудника.
     */
    public function test_the_talk_is_sent_to_the_model_as_turns(): void
    {
        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');
        $this->remember($reader, 'Чем разбавляют фасадную краску?', 'Водой, не более 10 % объёма.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replyingInTurn(
            'Сколько сохнет фасадная краска?',
            'Четыре часа [источник 1].',
        ));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'А сколько она сохнет?'])
            ->assertOk();

        $messages = $transport->payload()['messages'];

        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Чем разбавляют фасадную краску?', $messages[0]['content']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Водой, не более 10 % объёма.', $messages[1]['content']);

        // Свежий вопрос — последняя реплика, вместе с найденными фрагментами.
        $this->assertSame('user', $messages[2]['role']);
        $this->assertStringContainsString('А сколько она сохнет?', (string) $messages[2]['content']);
    }

    /**
     * Ссылки из прошлых ответов в разговор не переносятся.
     *
     * «[источник 2]» — это место фрагмента в списке того разговора, а в новом
     * вопросе список другой. Оставь разметку — и модель перенесёт номера в
     * свежий ответ, где они укажут не на то.
     */
    public function test_citation_markers_are_stripped_from_remembered_answers(): void
    {
        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');
        $this->remember($reader, 'Чем разбавляют фасадную краску?', 'Водой, не более 10 % объёма [источник 3].');

        $transport = $this->fakeModel(FakeAnthropicTransport::replyingInTurn(
            'Сколько сохнет фасадная краска?',
            'Четыре часа [источник 1].',
        ));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'А сколько она сохнет?'])
            ->assertOk();

        $sent = json_encode($transport->payloads(), JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString('источник 3', $sent);
        $this->assertStringContainsString('Водой, не более 10 % объёма.', $sent);
    }

    /**
     * Память у каждого своя.
     *
     * Вопросы одного сотрудника не должны достраивать вопросы другого, даже
     * если они сидят в одном отделе и спрашивают об одном.
     */
    public function test_one_persons_talk_never_reaches_another(): void
    {
        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');

        $this->remember($this->learner(), 'Чем разбавляют фасадную краску?', 'Водой, не более 10 % объёма.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет фасадная краска?'])
            ->assertOk();

        // Разговора у этого сотрудника нет, достраивать нечего — и лишнего
        // обращения к модели тоже нет.
        $this->assertSame(1, $transport->calls());

        $sent = json_encode($transport->payload()['messages'], JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString('Чем разбавляют', $sent);
    }

    /** Очистив переписку, сотрудник получает разговор с чистого листа. */
    public function test_clearing_the_conversation_clears_what_is_remembered(): void
    {
        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');
        $this->remember($reader, 'Чем разбавляют фасадную краску?', 'Водой, не более 10 % объёма.');

        $this->actingAs($reader)->deleteJson(route('lms.ask.forget'))->assertNoContent();

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет фасадная краска?'])
            ->assertOk();

        $this->assertSame(1, $transport->calls(), 'Достраивали вопрос по разговору, который сотрудник стёр.');
    }

    /** Помнится столько кругов, сколько разрешено настройкой, и самые свежие. */
    public function test_only_the_configured_number_of_turns_is_remembered(): void
    {
        config(['ai.conversation_turns' => 2]);

        $reader = $this->learner();

        $this->publishedLesson('Покраска стен', 'Фасадная краска сохнет не менее четырёх часов.');

        $this->remember($reader, 'Самый первый вопрос?', 'Самый первый ответ.');
        $this->remember($reader, 'Второй вопрос?', 'Второй ответ.');
        $this->remember($reader, 'Третий вопрос?', 'Третий ответ.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replyingInTurn(
            'Сколько сохнет фасадная краска?',
            'Четыре часа [источник 1].',
        ));

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'А сколько она сохнет?'])
            ->assertOk();

        $messages = $transport->payload()['messages'];

        // Два круга разговора плюс свежий вопрос.
        $this->assertCount(5, $messages);
        $this->assertSame('Второй вопрос?', $messages[0]['content']);

        $this->assertStringNotContainsString(
            'Самый первый вопрос?',
            json_encode($transport->payloads(), JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /* ---------- helpers ---------- */

    /**
     * Круг разговора, уже состоявшийся: так его хранит журнал.
     *
     * Отдельной таблицы у памяти нет — она читается из журнала, и поднимать
     * ради подготовки целый обмен с моделью незачем.
     */
    private function remember(User $reader, string $question, string $answer): void
    {
        ConsultantQuestion::query()->create([
            'user_id' => $reader->getKey(),
            'question' => $question,
            'answer' => $answer,
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
