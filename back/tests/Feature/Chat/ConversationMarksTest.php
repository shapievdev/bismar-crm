<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Jobs\SendPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Приглушить разговор и поднять его наверх.
 *
 * Обе отметки личные: приглушённая одним группа второму звонит по-прежнему, и
 * закреплённая одним висит наверху только у него.
 */
final class ConversationMarksTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_a_conversation_is_muted_and_unmuted(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->postJson(route('chat.conversations.mute', $conversation), ['muted' => true])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true);

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.is_muted', true);

        $this->actingAs($me)
            ->postJson(route('chat.conversations.mute', $conversation), ['muted' => false])
            ->assertOk()
            ->assertJsonPath('data.is_muted', false);
    }

    /** Отметка личная: собеседник о ней не знает и звук не теряет. */
    public function test_muting_is_personal(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->postJson(route('chat.conversations.mute', $conversation), ['muted' => true])
            ->assertOk();

        $this->actingAs($other)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.is_muted', false);
    }

    /** Приглушённый разговор не звонит: уведомление на устройство не уходит. */
    public function test_a_muted_conversation_sends_no_push(): void
    {
        Queue::fake();

        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->postJson(route('chat.conversations.mute', $conversation), ['muted' => true])
            ->assertOk();

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Обед в час'])
            ->assertCreated();

        Queue::assertNotPushed(SendPush::class);
    }

    /**
     * Позванный по имени слышит и в приглушённом.
     *
     * Приглушают обсуждение, а не себя: вопрос, заданный лично, обязан дойти —
     * иначе упоминание в приглушённой группе не значит ничего.
     */
    public function test_a_mention_still_reaches_a_muted_conversation(): void
    {
        Queue::fake();

        $me = $this->employee();
        $other = $this->employee();
        $group = $this->groupOf($other, [$me]);

        $this->actingAs($me)
            ->postJson(route('chat.conversations.mute', $group), ['muted' => true])
            ->assertOk();

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $group), [
                'body' => 'Посмотришь?',
                'mentions' => [$me->id],
            ])
            ->assertCreated();

        Queue::assertPushed(SendPush::class);
    }

    /** Закреплённые идут первыми, как бы давно в них ни говорили. */
    public function test_pinned_conversations_come_first(): void
    {
        $me = $this->employee();

        $old = $this->conversationBetween($me, $this->employee());
        $this->saidIn($old, $me, 'Давнее');

        $this->travel(1)->seconds();

        $fresh = $this->conversationBetween($me, $this->employee());
        $this->saidIn($fresh, $me, 'Свежее');

        // До закрепления наверху свежее.
        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $fresh->id);

        $this->actingAs($me)
            ->postJson(route('chat.conversations.pin', $old), ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.1.id', $fresh->id);
    }

    /** Чужой разговор не приглушить: его вообще не видно. */
    public function test_an_outsider_cannot_mark_a_conversation(): void
    {
        $strangers = $this->conversationBetween($this->employee(), $this->employee());

        $this->actingAs($this->employee())
            ->postJson(route('chat.conversations.mute', $strangers), ['muted' => true])
            ->assertForbidden();
    }
}
