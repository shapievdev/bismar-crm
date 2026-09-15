<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Удаление выделенного в ленте — несколько реплик разом.
 *
 * Главное здесь не «удаляет», а «удаляет всё или не удаляет ничего»: вернуть
 * удалённое нельзя, и половинчатый исход — худший из возможных.
 */
final class SelectedMessagesTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_selected_messages_are_deleted_at_once(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $first = $this->saidIn($conversation, $me, 'Раз');
        $second = $this->saidIn($conversation, $me, 'Два');

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy-many', $conversation), [
                'message_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSame(0, $conversation->messages()->count());
    }

    /**
     * Среди выделенного чужое — не удаляется ничего.
     *
     * Иначе девять своих реплик исчезли бы, а на десятой человек получил бы
     * отказ — и вернуть их было бы нечем.
     */
    public function test_nothing_is_deleted_when_one_of_them_is_not_yours(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $mine = $this->saidIn($conversation, $me, 'Своё');
        $theirs = $this->saidIn($conversation, $other, 'Чужое');

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy-many', $conversation), [
                'message_ids' => [$mine->id, $theirs->id],
            ])
            ->assertForbidden();

        $this->assertSame(2, $conversation->messages()->count());
    }

    /** Заведший группу убирает из неё и чужое: за порядок отвечает он. */
    public function test_the_group_owner_removes_other_people_messages(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $first = $this->saidIn($group, $mate, 'Раз');
        $second = $this->saidIn($group, $mate, 'Два');

        $this->actingAs($owner)
            ->deleteJson(route('chat.messages.destroy-many', $group), [
                'message_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);
    }

    /** Реплики из соседней переписки к своему адресу не подставляются. */
    public function test_messages_from_another_conversation_are_refused(): void
    {
        $me = $this->employee();
        $mine = $this->conversationBetween($me, $this->employee());

        $elsewhere = $this->conversationBetween($this->employee(), $this->employee());
        $theirs = $this->saidIn($elsewhere, $elsewhere->participants()->firstOrFail(), 'Чужое');

        $this->actingAs($me)
            ->deleteJson(route('chat.messages.destroy-many', $mine), [
                'message_ids' => [$theirs->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('message_ids.0');

        $this->assertNotNull(Message::query()->find($theirs->id));
    }
}
