<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\MessageReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Отклик на реплику одним знаком.
 *
 * Проверяется главное свойство: у одного человека об одной реплике одно мнение.
 * Тем же знаком отклик снимается, другим — заменяется, и в обоих случаях второй
 * строки в базе не появляется.
 */
final class ReactionsTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_a_reaction_is_placed_and_counted(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);
        $message = $this->saidIn($conversation, $other, 'Сверка завтра в десять');

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.mine', '👍')
            ->assertJsonPath('data.reactions.0.emoji', '👍')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.user_ids.0', $me->id);
    }

    /** Тот же знак второй раз — это «передумал». */
    public function test_the_same_emoji_twice_takes_the_reaction_back(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Готово');

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '✅'])
            ->assertOk();

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '✅'])
            ->assertOk()
            ->assertJsonPath('data.mine', null)
            ->assertJsonPath('data.reactions', []);

        $this->assertSame(0, MessageReaction::query()->count());
    }

    /** Другой знак — это «передумал иначе», а не второй отклик. */
    public function test_another_emoji_replaces_the_first(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Спорно');

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '👍'])
            ->assertOk();

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '👎'])
            ->assertOk()
            ->assertJsonPath('data.mine', '👎')
            ->assertJsonCount(1, 'data.reactions');

        $this->assertSame(1, MessageReaction::query()->count());
    }

    /** Набор знаков закрытый: своё под чужой репликой не поставишь. */
    public function test_an_emoji_outside_the_set_is_refused(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $message = $this->saidIn($conversation, $me, 'Что угодно');

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '💩'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('emoji');
    }

    /** В чужой разговор не откликаются — его вообще не видно. */
    public function test_an_outsider_cannot_react(): void
    {
        $conversation = $this->conversationBetween($this->employee(), $this->employee());
        $message = $this->saidIn($conversation, $conversation->participants()->first(), 'Наше');

        $this->actingAs($this->employee())
            ->postJson(route('chat.messages.react', [$conversation, $message]), ['emoji' => '👍'])
            ->assertForbidden();
    }

    /** Реплика из соседней переписки не откликается по чужому адресу. */
    public function test_a_message_from_another_conversation_is_not_found(): void
    {
        $me = $this->employee();
        $mine = $this->conversationBetween($me, $this->employee());

        $elsewhere = $this->conversationBetween($this->employee(), $this->employee());
        $theirs = $this->saidIn($elsewhere, $elsewhere->participants()->first(), 'Чужое');

        $this->actingAs($me)
            ->postJson(route('chat.messages.react', [$mine, $theirs]), ['emoji' => '👍'])
            ->assertNotFound();
    }

    /** Отклики приезжают вместе с лентой, а не отдельным запросом. */
    public function test_reactions_come_with_the_thread(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);
        $message = $this->saidIn($conversation, $other, 'Принято?');

        MessageReaction::create(['message_id' => $message->id, 'user_id' => $me->id, 'emoji' => '👍']);
        MessageReaction::create(['message_id' => $message->id, 'user_id' => $other->id, 'emoji' => '👍']);

        $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('data.0.reactions.0.emoji', '👍')
            ->assertJsonPath('data.0.reactions.0.count', 2);
    }
}
