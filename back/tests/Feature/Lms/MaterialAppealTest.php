<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Conversation;
use App\Models\Course;
use App\Models\Message;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * «Ответа не хватило» и «здесь написано неверно»: замечание к материалу уходит
 * личным сообщением тому, кто его правит, с карточкой над репликой.
 */
final class MaterialAppealTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Сообщение рассылает уведомления; здесь проверяют не их.
        Queue::fake();
    }

    /** Курс с автором и одним ответственным. */
    private function course(User $author, ?User $expert = null): Course
    {
        $course = Course::factory()->published()->create([
            'author_id' => $author->id,
            'title' => 'Работа с возражениями',
        ]);

        if ($expert !== null) {
            $course->experts()->attach($expert->id);
        }

        return $course;
    }

    public function test_a_reader_writes_to_the_author_of_a_course(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $course = $this->course($author);

        $this->actingAs($reader)
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $author->id,
                'reason' => 'missing',
                'body' => 'Не сказано, что делать с возражением про цену.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation_id', fn (mixed $id): bool => is_int($id));

        $message = Message::query()->latest('id')->firstOrFail();

        $this->assertSame('Не сказано, что делать с возражением про цену.', $message->body);

        // Порядок ключей сравнивать нечего: jsonb хранит их по-своему.
        $this->assertEquals([
            'kind' => 'course',
            'title' => 'Работа с возражениями',
            'context' => null,
            'url' => '/lms/'.$course->slug,
            'reason' => 'missing',
        ], $message->about);

        // Личная переписка на двоих — та самая, куда автор и ответит.
        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        $this->assertEqualsCanonicalizing(
            [$reader->id, $author->id],
            $conversation->participants()->pluck('users.id')->map(intval(...))->all(),
        );
    }

    /**
     * Карточка приходит вместе с сообщением: читателю переписки видно, с чего
     * начался разговор, — и подписи собираются на отдаче, а не хранятся.
     */
    public function test_the_message_carries_a_card_about_the_material(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $course = $this->course($author);

        $this->actingAs($reader)
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $author->id,
                'reason' => 'incorrect',
                'body' => 'Скидка теперь не 10, а 15 процентов.',
            ])
            ->assertCreated();

        $conversation = Conversation::query()->firstOrFail();

        $this->actingAs($author)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('data.0.about.kind', 'course')
            ->assertJsonPath('data.0.about.kind_label', 'Курс')
            ->assertJsonPath('data.0.about.title', 'Работа с возражениями')
            ->assertJsonPath('data.0.about.reason_label', 'Здесь написано неверно')
            ->assertJsonPath('data.0.about.url', '/lms/'.$course->slug);
    }

    /**
     * Урок ведёт к тому же человеку, что и курс, и карточка называет курс:
     * одно название урока не говорит, где его искать.
     */
    public function test_an_appeal_from_a_lesson_names_its_course(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create([
            'author_id' => $author->id,
            'title' => 'Основы',
        ]);
        $lesson = $course->lessons()->firstOrFail();

        $this->actingAs($reader)
            ->postJson(route('lms.lessons.appeal', $lesson), [
                'recipient_id' => $author->id,
                'reason' => 'missing',
                'body' => 'В уроке нет примера.',
            ])
            ->assertCreated();

        $about = Message::query()->latest('id')->firstOrFail()->about;

        $this->assertSame('lesson', $about['kind']);
        $this->assertSame('Основы', $about['context']);
        $this->assertSame('/lms/'.$course->slug.'/lessons/'.$lesson->id, $about['url']);
    }

    public function test_an_appeal_goes_from_a_document_too(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $document = Regulation::factory()->published()->create([
            'author_id' => $author->id,
            'title' => 'Кассовая дисциплина',
        ]);

        $this->actingAs($reader)
            ->postJson(route('lms.documents.appeal', $document), [
                'recipient_id' => $author->id,
                'reason' => 'incorrect',
                'body' => 'Смена закрывается в 22:00, а не в 21:00.',
            ])
            ->assertCreated();

        $this->assertSame('document', Message::query()->latest('id')->firstOrFail()->about['kind']);
    }

    /* ---------- Кому можно писать ---------- */

    /**
     * Список адресатов — автор и ответственные, без повторов: автор часто и
     * есть ответственный.
     */
    public function test_the_recipients_are_the_author_and_the_experts(): void
    {
        $author = $this->author();
        $expert = $this->learner();
        $course = $this->course($author, $expert);
        $course->experts()->attach($author->id);

        $people = $this->actingAs($this->learner())
            ->getJson(route('lms.courses.appeal.recipients', $course))
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(
            [$author->id, $expert->id],
            array_column($people, 'id'),
        );
    }

    public function test_a_stranger_cannot_be_named_as_the_recipient(): void
    {
        $reader = $this->learner();
        $course = $this->course($this->author());

        $this->actingAs($reader)
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $this->learner()->id,
                'reason' => 'missing',
                'body' => 'Поправьте, пожалуйста.',
            ])
            ->assertForbidden();

        $this->assertSame(0, Message::query()->count());
    }

    /** Автор правит материал, а не заводит с собой переписку об этом. */
    public function test_an_author_does_not_write_to_themselves(): void
    {
        $author = $this->author();
        $course = $this->course($author);

        $this->actingAs($author)
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $author->id,
                'reason' => 'missing',
                'body' => 'Сам себе.',
            ])
            ->assertForbidden();
    }

    /**
     * Уволенного в списке нет: писать ему некуда, в систему он больше не
     * входит.
     */
    public function test_a_dismissed_author_is_not_offered(): void
    {
        $author = $this->author();
        $expert = $this->author();
        $course = $this->course($author, $expert);

        $author->forceFill(['dismissed_at' => now()])->save();

        $people = $this->actingAs($this->learner())
            ->getJson(route('lms.courses.appeal.recipients', $course))
            ->assertOk()
            ->json('data');

        $this->assertSame([$expert->id], array_column($people, 'id'));
    }

    /* ---------- Без текста не отправляется ---------- */

    public function test_an_appeal_without_a_message_is_rejected(): void
    {
        $course = $this->course($this->author());

        $this->actingAs($this->learner())
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $course->author_id,
                'reason' => 'missing',
                'body' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_a_reason_outside_the_two_buttons_is_rejected(): void
    {
        $course = $this->course($this->author());

        $this->actingAs($this->learner())
            ->postJson(route('lms.courses.appeal', $course), [
                'recipient_id' => $course->author_id,
                'reason' => 'whatever',
                'body' => 'Что-то не так.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    /**
     * Материал, закрытый очередью плана обучения, не даёт написать и о себе:
     * читать его этот человек ещё не должен.
     */
    public function test_a_locked_course_takes_no_appeals(): void
    {
        $learner = $this->learner();
        $assigned = Course::factory()->withLessons(1)->create();
        $other = $this->course($this->author());

        $this->actingAs($this->administrator())
            ->putJson(route('lms.plans.update', $learner), [
                'items' => [['type' => 'course', 'id' => $assigned->id]],
            ])
            ->assertOk();

        $this->actingAs($learner)
            ->postJson(route('lms.courses.appeal', $other), [
                'recipient_id' => $other->author_id,
                'reason' => 'missing',
                'body' => 'Хотел бы уточнить.',
            ])
            ->assertForbidden();
    }
}
