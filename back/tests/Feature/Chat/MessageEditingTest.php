<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationKind;
use App\Enums\MessageKind;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageEdited;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Ответ, правка и удаление уже сказанного.
 *
 * Главное здесь — что подпись под сообщением остаётся правдой: переписать чужие
 * слова от чужого имени не может никто, включая администратора, которого
 * `Gate::before` проводит мимо любой политики.
 */
final class MessageEditingTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Ответ помнит, на что отвечали, и приносит цитату с собой. */
    public function test_a_reply_quotes_the_message_it_answers(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $original = $this->say($conversation, $other, 'Во сколько встреча?');

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'В три',
                'reply_to_id' => $original->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to.id', $original->id)
            ->assertJsonPath('data.reply_to.excerpt', 'Во сколько встреча?')
            ->assertJsonPath('data.reply_to.author.id', $other->id)
            ->assertJsonPath('data.reply_to.deleted', false);
    }

    /**
     * Отвечать на реплику из соседнего разговора нельзя.
     *
     * Иначе ответ вытаскивал бы наружу чужой текст: цитата приезжает вместе с
     * ним, и права на ту переписку уже никто не спрашивает.
     */
    public function test_a_reply_cannot_reach_into_another_conversation(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $elsewhere = $this->conversationBetween($this->employee(), $this->employee());
        $secret = $this->say($elsewhere, $elsewhere->participants()->first(), 'Совещание в 9');

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'Ага',
                'reply_to_id' => $secret->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to_id');
    }

    /** Автор правит своё: текст меняется, появляется отметка о правке. */
    public function test_an_author_edits_their_own_message(): void
    {
        Event::fake([MessageEdited::class]);

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->say($conversation, $me, 'встреча в 4');

        $this->actingAs($me)
            ->patchJson(route('chat.messages.update', [$conversation, $message]), ['body' => 'встреча в 5'])
            ->assertOk()
            ->assertJsonPath('data.body', 'встреча в 5');

        $this->assertNotNull($message->refresh()->edited_at);
        Event::assertDispatched(MessageEdited::class);
    }

    /**
     * Чужое не правит никто — и администратор в том числе.
     *
     * Это и есть та проверка, ради которой запрет продублирован в действии:
     * `Gate::before` пропускает администратора мимо политики, и одной политики
     * здесь не хватило бы.
     */
    public function test_nobody_rewrites_another_persons_words(): void
    {
        $author = $this->employee();
        $conversation = $this->conversationBetween($author, $this->employee());
        $message = $this->say($conversation, $author, 'я подготовлю отчёт');

        foreach ([$this->employee(), $this->administrator(), $this->superAdministrator()] as $meddler) {
            $conversation->participants()->syncWithoutDetaching([$meddler->id]);

            $this->actingAs($meddler)
                ->patchJson(route('chat.messages.update', [$conversation, $message]), ['body' => 'я ничего не буду делать'])
                ->assertForbidden();
        }

        $this->assertSame('я подготовлю отчёт', $message->refresh()->body);
        $this->assertNull($message->edited_at);
    }

    /** Удаление уносит текст и вложения, но оставляет место, куда указывал ответ. */
    public function test_deleting_removes_the_content_but_keeps_the_anchor(): void
    {
        Storage::fake('s3');
        Event::fake([MessageDeleted::class]);

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $message = $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'вот смета',
                'attachments' => [UploadedFile::fake()->create('смета.pdf', 12)],
            ])
            ->assertCreated();

        $id = (int) $message->json('data.id');

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy', [$conversation, $id]))
            ->assertNoContent();

        $deleted = Message::withTrashed()->findOrFail($id);

        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->body, 'Текст удалённой реплики не должен оставаться в базе.');
        $this->assertSame(0, $deleted->attachments()->count());

        // Из ленты она пропала.
        $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);

        Event::assertDispatched(MessageDeleted::class);
    }

    /** Ответ на удалённое говорит, что отвечали на что-то, чего больше нет. */
    public function test_a_reply_to_a_deleted_message_says_so(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $original = $this->say($conversation, $other, 'заберёшь документы?');

        $reply = $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'заберу',
                'reply_to_id' => $original->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($other)
            ->deleteJson(route('chat.messages.destroy', [$conversation, $original]))
            ->assertNoContent();

        $thread = $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->json('data');

        $shown = collect($thread)->firstWhere('id', $reply);

        $this->assertTrue($shown['reply_to']['deleted']);
        $this->assertNull($shown['reply_to']['excerpt']);
    }

    /** За порядком в группе следит тот, кто её завёл: чужое он убрать может. */
    public function test_the_group_owner_may_delete_someone_elses_message(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $message = $this->say($group, $mate, 'лишнее');

        $this->actingAs($owner)
            ->deleteJson(route('chat.messages.destroy', [$group, $message]))
            ->assertNoContent();

        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    /** Системную отметку не правит и не удаляет никто: её писал не человек. */
    public function test_a_system_note_is_left_alone(): void
    {
        $owner = $this->employee();
        $group = $this->groupOf($owner, [$this->employee()]);

        $note = $group->messages()->create([
            'kind' => MessageKind::System,
            'body' => 'Группа создана',
        ]);

        $this->actingAs($owner)
            ->patchJson(route('chat.messages.update', [$group, $note]), ['body' => 'не было такого'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->deleteJson(route('chat.messages.destroy', [$group, $note]))
            ->assertForbidden();
    }

    /** Правка не превращается в удаление: пустая реплика не сохраняется. */
    public function test_an_edit_cannot_empty_a_message(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->say($conversation, $me, 'что-то сказал');

        $this->actingAs($me)
            ->patchJson(route('chat.messages.update', [$conversation, $message]), ['body' => '   '])
            ->assertForbidden();

        $this->assertSame('что-то сказал', $message->refresh()->body);
    }

    /** Реплику из соседней переписки не тронуть, подставив свой разговор в адрес. */
    public function test_a_message_from_another_conversation_is_not_found(): void
    {
        $me = $this->employee();
        $mine = $this->conversationBetween($me, $this->employee());

        $elsewhere = $this->conversationBetween($this->employee(), $this->employee());
        $stranger = $this->say($elsewhere, $elsewhere->participants()->first(), 'чужое');

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy', [$mine, $stranger]))
            ->assertNotFound();

        $this->assertNull($stranger->refresh()->deleted_at);
    }

    private function employee(): User
    {
        return User::factory()->create();
    }

    private function say(Conversation $conversation, User $author, string $body): Message
    {
        return $conversation->messages()->create([
            'user_id' => $author->getKey(),
            'kind' => MessageKind::Text,
            'body' => $body,
        ]);
    }

    private function conversationBetween(User $first, User $second): Conversation
    {
        $conversation = Conversation::create([
            'kind' => ConversationKind::Direct,
            'direct_key' => Conversation::directKey((int) $first->id, (int) $second->id),
            'created_by_id' => $first->id,
        ]);

        $conversation->participants()->attach([$first->id, $second->id]);

        return $conversation;
    }

    /**
     * @param  list<User>  $mates
     */
    private function groupOf(User $owner, array $mates): Conversation
    {
        $group = Conversation::create([
            'kind' => ConversationKind::Group,
            'title' => 'Отдел',
            'created_by_id' => $owner->id,
        ]);

        $group->participants()->attach(array_merge([$owner->id], array_map(fn (User $m) => $m->id, $mates)));

        return $group;
    }
}