<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\Permission;
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
 * Кто отвечает за курс — и когда об этом говорит консультант.
 *
 * База знаний конечна, а вопросы нет: рано или поздно спрашивают то, чего в
 * материале не написано. До сих пор такой разговор заканчивался ничем. Теперь у
 * курса есть живые люди, и когда ответа не нашлось, сотруднику называют, к кому
 * с этим идти.
 */
final class CourseExpertsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Ответственных назначает тот, кто правит курс. */
    public function test_an_editor_appoints_the_people_responsible_for_a_course(): void
    {
        $course = Course::factory()->published()->create();
        $expert = $this->person('Яковлев');

        $this->actingAs($this->author())
            ->putJson(route('lms.courses.experts.update', $course), ['experts' => [$expert->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expert->id);

        $this->assertTrue($course->experts()->whereKey($expert->id)->exists());
    }

    /** Список задаётся целиком: чего в нём нет, того больше не будет. */
    public function test_the_list_is_replaced_whole(): void
    {
        $course = Course::factory()->published()->create();
        $first = $this->person('Абрамов');
        $second = $this->person('Ёлкин');

        $author = $this->author();

        $this->actingAs($author)
            ->putJson(route('lms.courses.experts.update', $course), ['experts' => [$first->id, $second->id]])
            ->assertOk()
            // По фамилии и с учётом ICU: под C-сортировкой «Ёлкин» оказался бы
            // после «Яковлева».
            ->assertJsonPath('data.0.name', $first->name)
            ->assertJsonPath('data.1.name', $second->name);

        $this->actingAs($author)
            ->putJson(route('lms.courses.experts.update', $course), ['experts' => [$second->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);
    }

    /** Читателю ответственные видны в самом курсе — к ним он и пойдёт. */
    public function test_a_reader_sees_who_is_responsible_on_the_course(): void
    {
        $course = Course::factory()->published()->create();
        $expert = $this->person('Яковлев');
        $course->experts()->attach($expert->id);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.show', $course))
            ->assertOk()
            ->assertJsonCount(1, 'data.experts')
            ->assertJsonPath('data.experts.0.name', $expert->name)
            ->assertJsonPath('data.experts.0.email', $expert->email);
    }

    /** Назначать ответственных читатель не может. */
    public function test_a_reader_cannot_appoint_anyone(): void
    {
        $course = Course::factory()->published()->create();

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->putJson(route('lms.courses.experts.update', $course), ['experts' => [$this->person('Яковлев')->id]])
            ->assertForbidden();

        $this->assertSame(0, $course->experts()->count());
    }

    /**
     * Ответа не нашлось — консультант называет, у кого спросить.
     *
     * Ради этого ответственные и заводятся: разговор, который прежде
     * заканчивался словами «прямого ответа нет», теперь заканчивается именем.
     */
    public function test_the_consultant_names_the_person_to_ask_when_it_has_no_answer(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Работа с претензиями и возвратами']);
        $this->lessonIn($course, 'Общие сведения', 'Здесь пока пусто.');

        $expert = $this->person('Яковлев');
        $course->experts()->attach($expert->id);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying(
            'Прямого ответа на это в материалах нет. Есть курс про возвраты [источник 1].',
        ));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как оформить возврат бракованного товара?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.experts')
            ->assertJsonPath('data.experts.0.name', $expert->name)
            ->assertJsonPath('data.experts.0.email', $expert->email)
            ->assertJsonPath('data.experts.0.course_title', 'Работа с претензиями и возвратами');

        // Модели имён не показывают вовсе: названный ею человек может оказаться
        // выдуманным, и сотрудник напишет по выдуманному адресу.
        $sent = json_encode($transport->payload(), JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString($expert->name, $sent);
        $this->assertStringNotContainsString($expert->email, $sent);
    }

    /** Ответ нашёлся — никого спрашивать не нужно, и совета нет. */
    public function test_a_grounded_answer_names_nobody(): void
    {
        $course = Course::factory()->published()->create();
        $this->lessonIn($course, 'Работа с возражениями', 'Когда клиент говорит «дорого», выслушайте и уточните.');

        $course->experts()->attach($this->person('Яковлев')->id);

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте и уточните [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.experts', []);
    }

    /** Совет остаётся в переписке снимком: ответственные со временем меняются. */
    public function test_the_advice_is_kept_with_the_conversation(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Работа с претензиями и возвратами']);
        $this->lessonIn($course, 'Общие сведения', 'Здесь пока пусто.');

        $expert = $this->person('Яковлев');
        $course->experts()->attach($expert->id);

        $this->fakeModel(FakeAnthropicTransport::replying('Прямого ответа на это в материалах нет [источник 1].'));

        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.ask'), ['question' => 'Как оформить возврат бракованного товара?'])
            ->assertOk();

        // Ответственного сняли — сказанное вчера от этого не меняется.
        $course->experts()->detach($expert->id);

        $this->actingAs($reader)
            ->getJson(route('lms.ask.history'))
            ->assertOk()
            ->assertJsonPath('data.0.answer.experts.0.name', $expert->name);

        $this->assertNotEmpty(ConsultantQuestion::query()->sole()->experts);
    }

    /* ---------- helpers ---------- */

    private function person(string $lastName): User
    {
        return User::factory()->create(['last_name' => $lastName]);
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
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
