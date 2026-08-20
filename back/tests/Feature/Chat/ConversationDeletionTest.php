<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationKind;
use App\Enums\DeletionScope;
use App\Enums\MessageKind;
use App\Events\Chat\ConversationRemoved;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Удаление переписки — у себя и у всех.
 *
 * Главное здесь — что «у себя» никогда не задевает собеседника, а «у всех» не
 * оставляет за собой ни строк, ни файлов. Между этими двумя и проходит вся
 * разница: первое обратимо и односторонне, второе окончательно и общее.
 */
final class ConversationDeletionTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Убранная у себя переписка уходит из своего списка — и только из своего. */
    public function test_deleting_a_conversation_for_myself_leaves_it_whole_for_the_other_person(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->say($conversation, $other, 'Здравствуйте');

        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $conversation), ['scope' => DeletionScope::Mine->value])
            ->assertOk();

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // У собеседника всё на месте — и разговор, и сказанное в нём.
        $this->actingAs($other)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_message.body', 'Здравствуйте');

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertSame(1, $conversation->messages()->count());
    }

    /**
     * Убранная переписка возвращается с новым словом — но без прошлого.
     *
     * Это и отличает «удалить у себя» от выхода: разговор не кончился, кончилась
     * его история у одного из двоих.
     */
    public function test_a_cleared_conversation_returns_empty_when_someone_writes_again(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->say($conversation, $other, 'Старое');

        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $conversation))
            ->assertOk();

        // Секунда врозь: метка удаления и новое сообщение не должны совпасть до
        // мгновения — иначе «позже» ничего не значит.
        Carbon::setTestNow(now()->addSecond());

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Новое'])
            ->assertCreated();

        Carbon::setTestNow();

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_message.body', 'Новое');

        // В ленте — только сказанное после удаления.
        $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Новое');

        // А у собеседника лента по-прежнему полная.
        $this->actingAs($other)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Убранное не всплывает строчкой в списке, даже когда всплыть больше нечему.
     *
     * Переписка вернулась новым сообщением, его удалили — и последним снова
     * стало давнее, которого для читателя нет. Показать его в списке значило бы
     * вернуть удалённое одной строкой.
     */
    public function test_a_cleared_past_never_returns_to_the_list(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->say($conversation, $other, 'Старое');

        $this->actingAs($me)->deleteJson(route('chat.conversations.destroy', $conversation))->assertOk();

        Carbon::setTestNow(now()->addSecond());

        $fresh = $this->actingAs($other)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Новое'])
            ->assertCreated()
            ->json('data.id');

        Carbon::setTestNow();

        $this->actingAs($other)
            ->deleteJson(route('chat.messages.destroy', [
                'conversation' => $conversation->id,
                'message' => $fresh,
            ]))
            ->assertNoContent();

        // Переписка в списке осталась — пустой строчкой, без прежних слов.
        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonMissingPath('data.0.last_message');
    }

    /** Непрочитанное из убранной истории не висит цифрой. */
    public function test_clearing_a_conversation_takes_its_unread_with_it(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->say($conversation, $other, 'Непрочитанное');

        $this->actingAs($me)
            ->getJson(route('chat.unread'))
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $this->actingAs($me)->deleteJson(route('chat.conversations.destroy', $conversation))->assertOk();

        $this->actingAs($me)
            ->getJson(route('chat.unread'))
            ->assertOk()
            ->assertJsonPath('data.unread', 0);
    }

    /** Умолчание — меньшее из двух: не сказавший, чего хочет, удаляет у себя. */
    public function test_deletion_without_a_scope_only_touches_the_asker(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)->deleteJson(route('chat.conversations.destroy', $conversation))->assertOk();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    /**
     * Личную переписку удаляет у обоих любой из двоих — вместе с файлами.
     *
     * Файл уходит и из базы, и из хранилища: удалённое не должно оставаться
     * лежать в бакете и открываться по прежней ссылке.
     */
    public function test_a_direct_conversation_is_deleted_for_both_with_its_files(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($other)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'Вот прайс',
                'attachments' => [UploadedFile::fake()->create('прайс.pdf', 16, 'application/pdf')],
            ])
            ->assertCreated();

        $this->assertCount(1, Storage::disk('s3')->allFiles());

        // Удаляет тот, кто файл не присылал: разговор двоих принадлежит обоим.
        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $conversation), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('conversation_participants', ['conversation_id' => $conversation->id]);
        $this->assertSame([], Storage::disk('s3')->allFiles());

        $this->actingAs($other)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Удалённая пара может начать разговор заново — ключ пары освободился. */
    public function test_a_pair_can_start_over_after_deleting_everything(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $conversation), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertOk();

        $started = $this->actingAs($me)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Direct->value,
                'user_id' => $other->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame($conversation->id, $started);
    }

    /** Группу стирает у всех только тот, кто её завёл. Остальным — выход. */
    public function test_only_the_owner_deletes_a_group_for_everyone(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $this->actingAs($mate)
            ->deleteJson(route('chat.conversations.destroy', $group), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', ['id' => $group->id]);

        $this->actingAs($owner)
            ->deleteJson(route('chat.conversations.destroy', $group), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('conversations', ['id' => $group->id]);

        $this->actingAs($mate)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Участник группы убирает её у себя, не трогая ни группу, ни остальных.
     *
     * Из состава он при этом не выходит: он не прощался — он лишь убрал
     * разговор с глаз. Напишут снова — группа вернётся.
     */
    public function test_a_group_member_may_clear_a_group_without_leaving_it(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $this->say($group, $owner, 'Планёрка в девять');

        $this->actingAs($mate)->deleteJson(route('chat.conversations.destroy', $group))->assertOk();

        $this->actingAs($mate)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertTrue($group->refresh()->includes($mate));
        $this->assertDatabaseHas('conversations', ['id' => $group->id]);

        $this->actingAs($owner)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Посторонний не удаляет чужую переписку — ни себе, ни всем. */
    public function test_a_stranger_deletes_nothing(): void
    {
        $stranger = $this->employee();
        $conversation = $this->conversationBetween($this->employee(), $this->employee());

        $this->actingAs($stranger)
            ->deleteJson(route('chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->deleteJson(route('chat.conversations.destroy', $conversation), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    /**
     * О пропаже узнают все, кто в переписке был, — и никто больше.
     *
     * Убранная у себя объявляется только себе: у собеседника ничего не
     * изменилось, и гасить у него строчку списка не за что.
     */
    public function test_removal_is_announced_to_the_right_people(): void
    {
        Event::fake([ConversationRemoved::class]);

        $me = $this->employee();
        $other = $this->employee();
        $mine = $this->conversationBetween($me, $other);
        $shared = $this->groupOf($me, [$other]);

        $this->actingAs($me)->deleteJson(route('chat.conversations.destroy', $mine))->assertOk();

        Event::assertDispatched(
            ConversationRemoved::class,
            fn (ConversationRemoved $event): bool => $event->conversationId === $mine->id
                && $event->recipients === [$me->id],
        );

        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $shared), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertOk();

        Event::assertDispatched(
            ConversationRemoved::class,
            fn (ConversationRemoved $event): bool => $event->conversationId === $shared->id
                && $event->recipients === [$me->id, $other->id],
        );
    }

    /* ---------- helpers ---------- */

    private function employee(): User
    {
        return User::factory()->create();
    }

    /**
     * Сказанное в переписке — вместе с отметкой времени на самой переписке.
     *
     * Отметку ставит SayInConversation, и без неё разговор не всплывает в
     * списке: именно по ней он туда и попадает.
     */
    private function say(Conversation $conversation, User $author, string $body): Message
    {
        $message = $conversation->messages()->create([
            'user_id' => $author->getKey(),
            'kind' => MessageKind::Text,
            'body' => $body,
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
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

        $group->participants()->attach([$owner->id, ...array_map(static fn (User $one): int => (int) $one->id, $mates)]);

        return $group;
    }
}
