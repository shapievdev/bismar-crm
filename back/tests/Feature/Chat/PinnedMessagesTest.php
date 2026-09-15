<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\MessageKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Закреплённое наверху переписки.
 *
 * Закрепление общее: полоса над лентой одна на всех, и потому право на неё уже
 * права ответить — в группе закрепляет только тот, кто её завёл.
 */
final class PinnedMessagesTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_a_message_is_pinned_and_comes_back_with_the_thread(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);
        $message = $this->saidIn($conversation, $other, 'Сверка в десять');

        $this->actingAs($me)
            ->postJson(route('chat.messages.pin', [$conversation, $message]))
            ->assertOk()
            ->assertJsonPath('data.id', $message->id);

        $this->actingAs($other)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('meta.pinned.0.id', $message->id);
    }

    /** Закрепление объясняет себя отметкой в ленте — иначе полоса берётся ниоткуда. */
    public function test_pinning_leaves_a_note_in_the_thread(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Бланк здесь');

        $this->actingAs($me)
            ->postJson(route('chat.messages.pin', [$conversation, $message]))
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'kind' => MessageKind::System->value,
            'body' => sprintf('%s закрепил сообщение', $me->name),
        ]);
    }

    public function test_a_message_is_unpinned(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Уже неактуально');

        $this->actingAs($me)->postJson(route('chat.messages.pin', [$conversation, $message]))->assertOk();

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.unpin', [$conversation, $message]))
            ->assertOk()
            ->assertJsonPath('data.pinned_at', null);

        $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('meta.pinned', []);
    }

    /** В группе полосу ведёт тот, кто группу завёл: иначе она станет второй лентой. */
    public function test_only_the_group_owner_pins(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);
        $message = $this->saidIn($group, $mate, 'Моё важное');

        $this->actingAs($mate)
            ->postJson(route('chat.messages.pin', [$group, $message]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('chat.messages.pin', [$group, $message]))
            ->assertOk();
    }

    /** Системную отметку закрепить нельзя: её писал не человек. */
    public function test_a_system_note_cannot_be_pinned(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $note = $conversation->messages()->create([
            'kind' => MessageKind::System,
            'body' => 'Кто-то вышел',
        ]);

        $this->actingAs($me)
            ->postJson(route('chat.messages.pin', [$conversation, $note]))
            ->assertForbidden();
    }

    /** Удалённое исчезает и с полосы: показывать там было бы нечего. */
    public function test_deleting_a_pinned_message_clears_the_bar(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Временное');

        $this->actingAs($me)->postJson(route('chat.messages.pin', [$conversation, $message]))->assertOk();
        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', [$conversation, $message]))->assertNoContent();

        $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('meta.pinned', []);
    }
}
