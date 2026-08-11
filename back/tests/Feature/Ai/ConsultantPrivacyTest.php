<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
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
 * Консультант отвечает по тому, что открыто спрашивающему.
 *
 * Пересказ закрытого курса выдаёт его не хуже, чем открытая страница, — и
 * выдаёт незаметно: сотрудник не узнаёт из ответа, что материал был чужим.
 * Поэтому отбор идёт в запросе, а не в изложении.
 */
final class ConsultantPrivacyTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_private_course_never_reaches_the_model_for_an_outsider(): void
    {
        $this->privateLesson(
            $this->author(),
            'Закрытая методика',
            'Когда клиент говорит «дорого», предложите рассрочку по закрытому регламенту.',
        );

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В материалах базы знаний об этом ничего нет.')
            ->assertJsonPath('data.sources', []);

        $this->assertFalse($transport->wasCalled(), 'Закрытый материал ушёл в модель постороннему.');
    }

    public function test_someone_admitted_gets_an_answer_from_the_private_course(): void
    {
        $lesson = $this->privateLesson(
            $this->author(),
            'Закрытая методика',
            'Когда клиент говорит «дорого», предложите рассрочку по закрытому регламенту.',
        );

        $member = $this->learner();
        $lesson->module->course->members()->attach($member);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Предложите рассрочку [источник 1].'));

        $this->actingAs($member)
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.lesson_id', $lesson->id);

        $this->assertTrue($transport->wasCalled());
    }

    /**
     * Название закрытого курса — уже сведения о нём.
     *
     * Перечень всего, что есть в базе, уходит модели вместе с указаниями,
     * чтобы она могла уверенно сказать «об этом у нас ничего нет». Попади туда
     * чужой приватный курс — и консультант предложил бы почитать материал,
     * которого спрашивающий не увидит.
     */
    public function test_the_name_of_a_private_course_is_hidden_from_outsiders(): void
    {
        $this->privateLesson($this->author(), 'Закрытая методика', 'Закрытый разбор возражений клиентов.');
        $this->publicLesson('Работа с возражениями', 'Открытый разбор возражений клиентов.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Ответ.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $sent = (string) json_encode($transport->payload(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Закрытый курс', $sent);
        $this->assertStringNotContainsString('Закрытая методика', $sent);
        $this->assertStringContainsString('Работа с возражениями', $sent);
    }

    /**
     * Личная часть перечня идёт отдельным блоком — после точки кэширования.
     *
     * Перечень открытых курсов одинаков у всех и потому кэшируется моделью
     * один на всю компанию. Приклей к нему то, что открыто лично этому
     * сотруднику, — и общего префикса не осталось бы ни у кого, включая тех,
     * у кого приватных курсов нет вовсе.
     */
    public function test_the_private_part_of_the_catalogue_sits_outside_the_cached_prefix(): void
    {
        $author = $this->author();
        $lesson = $this->privateLesson($author, 'Закрытая методика', 'Закрытый разбор возражений клиентов.');
        $lesson->module->course->update(['title' => 'Закрытый курс о продажах']);

        $this->publicLesson('Работа с возражениями', 'Открытый разбор возражений клиентов.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Ответ.'));

        $this->actingAs($author)
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $system = $transport->payload()['system'];

        $this->assertSame('ephemeral', $system[0]['cache_control']['type'] ?? null);
        $this->assertStringNotContainsString('Закрытый курс о продажах', $system[0]['text']);

        $this->assertArrayHasKey(1, $system, 'Личный перечень курсов не отправлен вовсе.');
        $this->assertArrayNotHasKey('cache_control', $system[1]);
        $this->assertStringContainsString('Закрытый курс о продажах', $system[1]['text']);
    }

    /**
     * Суперадминистратору открыто всё — в том числе и для консультанта: иначе
     * он читал бы курс глазами, но не мог бы о нём спросить.
     */
    public function test_a_superadministrator_may_ask_about_any_private_course(): void
    {
        $lesson = $this->privateLesson(
            $this->author(),
            'Закрытая методика',
            'Когда клиент говорит «дорого», предложите рассрочку по закрытому регламенту.',
        );

        $this->fakeModel(FakeAnthropicTransport::replying('Предложите рассрочку [источник 1].'));

        $this->actingAs($this->superAdministrator())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.lesson_id', $lesson->id);
    }

    /** Администратор к чужому закрытому курсу не допущен и здесь. */
    public function test_an_administrator_gets_nothing_from_a_private_course(): void
    {
        $this->privateLesson(
            $this->author(),
            'Закрытая методика',
            'Когда клиент говорит «дорого», предложите рассрочку по закрытому регламенту.',
        );

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->administrator())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В материалах базы знаний об этом ничего нет.');

        $this->assertFalse($transport->wasCalled());
    }

    /**
     * Журнал вопросов — тоже пересказ материала.
     *
     * Его читает автор, чтобы видеть пробелы в базе знаний, и ответ там лежит
     * целой строкой. Ответ, собранный из закрытого курса, виден только тем,
     * кому открыт сам курс, — иначе приватность обходилась бы чтением журнала.
     */
    public function test_the_journal_does_not_show_answers_drawn_from_a_private_course(): void
    {
        $author = $this->author();
        $lesson = $this->privateLesson(
            $author,
            'Закрытая методика',
            'Когда клиент говорит «дорого», предложите рассрочку по закрытому регламенту.',
        );

        $member = $this->learner();
        $lesson->module->course->members()->attach($member);

        $this->fakeModel(FakeAnthropicTransport::replying('Предложите рассрочку [источник 1].'));

        $this->actingAs($member)
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk();

        // Автору закрытого курса — видно: пробелы в нём чинить ему.
        $this->actingAs($author)
            ->getJson(route('ai.questions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Постороннему редактору — ни строки, ни следа в сводке.
        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.summary.answered', 0);

        $this->actingAs($this->superAdministrator())
            ->getJson(route('ai.questions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Вопрос по открытому материалу остаётся общим достоянием журнала. */
    public function test_the_journal_still_shows_questions_about_open_material(): void
    {
        $this->publicLesson('Работа с возражениями', 'Открытый разбор возражений клиентов.');

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'возражения клиентов'])
            ->assertOk();

        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
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

    private function privateLesson(User $author, string $title, string $content): Lesson
    {
        $course = Course::factory()->published()->closed()->create([
            'author_id' => $author->id,
            'title' => 'Закрытый курс',
        ]);

        return $this->lessonIn($course, $title, $content);
    }

    private function publicLesson(string $title, string $content): Lesson
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
