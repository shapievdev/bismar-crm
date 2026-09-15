<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Пересылка сказанного в другой разговор.
 *
 * Копией, а не ссылкой: пересылают затем, чтобы сказанное дошло в третий
 * разговор и там осталось — даже когда исходное удалят.
 */
final class ForwardingTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_a_message_is_copied_with_its_origin(): void
    {
        $me = $this->employee();
        $author = $this->employee();

        $source = $this->conversationBetween($me, $author);
        $target = $this->conversationBetween($me, $this->employee());

        $said = $this->saidIn($source, $author, 'Бланк лежит в папке «Сверки»');

        $this->actingAs($me)
            ->postJson(route('chat.messages.forward', $source), [
                'message_ids' => [$said->id],
                'to_conversation_id' => $target->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.body', 'Бланк лежит в папке «Сверки»')
            ->assertJsonPath('data.0.forwarded.author_name', $author->name)
            ->assertJsonPath('meta.conversation_id', $target->id);

        $this->assertSame(1, $target->messages()->count());
    }

    /** Пересланное переживает удаление исходного — в этом весь смысл копии. */
    public function test_the_copy_outlives_the_original(): void
    {
        $me = $this->employee();
        $author = $this->employee();

        $source = $this->conversationBetween($me, $author);
        $target = $this->conversationBetween($me, $this->employee());
        $said = $this->saidIn($source, $author, 'Сказанное однажды');

        $this->actingAs($me)->postJson(route('chat.messages.forward', $source), [
            'message_ids' => [$said->id],
            'to_conversation_id' => $target->id,
        ])->assertCreated();

        $this->actingAs($author)
            ->deleteJson(route('chat.messages.destroy', [$source, $said]))
            ->assertNoContent();

        $this->assertSame('Сказанное однажды', $target->messages()->first()?->body);
    }

    /**
     * Цепочка из трёх рук не подменяет автора последним звеном: пересылка
     * передаёт первоисточник.
     */
    public function test_forwarding_a_forward_keeps_the_first_author(): void
    {
        $me = $this->employee();
        $author = $this->employee();

        $source = $this->conversationBetween($me, $author);
        $middle = $this->conversationBetween($me, $this->employee());
        $last = $this->conversationBetween($me, $this->employee());

        $said = $this->saidIn($source, $author, 'Первоисточник');

        $first = $this->actingAs($me)->postJson(route('chat.messages.forward', $source), [
            'message_ids' => [$said->id],
            'to_conversation_id' => $middle->id,
        ])->assertCreated()->json('data.0.id');

        $this->actingAs($me)
            ->postJson(route('chat.messages.forward', $middle), [
                'message_ids' => [$first],
                'to_conversation_id' => $last->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.forwarded.author_name', $author->name);
    }

    /** Вложение уезжает своей копией: удаление одного не отнимает файл у второго. */
    public function test_an_attachment_is_copied_rather_than_shared(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $source = $this->conversationBetween($me, $this->employee());
        $target = $this->conversationBetween($me, $this->employee());

        $said = $this->actingAs($me)
            ->post(route('chat.messages.store', $source), [
                'attachments' => [UploadedFile::fake()->image('бланк.png')],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($me)->postJson(route('chat.messages.forward', $source), [
            'message_ids' => [$said],
            'to_conversation_id' => $target->id,
        ])->assertCreated();

        $original = Message::query()->findOrFail($said)->attachments()->firstOrFail();
        $copy = $target->messages()->firstOrFail()->attachments()->firstOrFail();

        $this->assertNotSame($original->path, $copy->path);
        Storage::disk('s3')->assertExists($copy->path);

        // Исходное удалено — файл копии остался на месте.
        $this->actingAs($me)->deleteJson(route('chat.messages.destroy', [$source, $said]))->assertNoContent();

        Storage::disk('s3')->assertExists($copy->path);
    }

    /** В чужой разговор не переслать: писать туда нельзя. */
    public function test_forwarding_into_a_conversation_you_are_not_in_is_refused(): void
    {
        $me = $this->employee();
        $source = $this->conversationBetween($me, $this->employee());
        $said = $this->saidIn($source, $me, 'Своё');

        $strangers = $this->conversationBetween($this->employee(), $this->employee());

        $this->actingAs($me)
            ->postJson(route('chat.messages.forward', $source), [
                'message_ids' => [$said->id],
                'to_conversation_id' => $strangers->id,
            ])
            ->assertForbidden();
    }

    /** Чужой разговор не вычитать по номерам, подставив его реплики к своему. */
    public function test_messages_from_another_conversation_are_refused(): void
    {
        $me = $this->employee();
        $mine = $this->conversationBetween($me, $this->employee());
        $target = $this->conversationBetween($me, $this->employee());

        $elsewhere = $this->conversationBetween($this->employee(), $this->employee());
        $theirs = $this->saidIn($elsewhere, $elsewhere->participants()->firstOrFail(), 'Чужое');

        $this->actingAs($me)
            ->postJson(route('chat.messages.forward', $mine), [
                'message_ids' => [$theirs->id],
                'to_conversation_id' => $target->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('message_ids.0');
    }

    /** Порядок — тот, в каком это было сказано, а не в каком выделяли. */
    public function test_forwarded_messages_keep_their_order(): void
    {
        $me = $this->employee();
        $source = $this->conversationBetween($me, $this->employee());
        $target = $this->conversationBetween($me, $this->employee());

        $first = $this->saidIn($source, $me, 'Раз');
        $second = $this->saidIn($source, $me, 'Два');

        $this->actingAs($me)
            ->postJson(route('chat.messages.forward', $source), [
                'message_ids' => [$second->id, $first->id],
                'to_conversation_id' => $target->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.body', 'Раз')
            ->assertJsonPath('data.1.body', 'Два');
    }
}
