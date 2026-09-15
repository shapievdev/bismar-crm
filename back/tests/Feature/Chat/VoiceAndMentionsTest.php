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
 * Надиктованное и позванные по имени.
 *
 * У голосового проверяется, что числами записи не подпишется что попало: волна
 * с кнопкой «играть» под архивом — это сломанный показ, а не вольность.
 *
 * У упоминаний — что позвать можно только того, кто в разговоре состоит: иначе
 * уведомление рассказало бы постороннему и о разговоре, и о сказанном в нём.
 */
final class VoiceAndMentionsTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    public function test_a_voice_message_keeps_its_waveform_and_length(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->post(route('chat.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('voice.webm', 40, 'audio/webm')],
                'voice' => ['duration_ms' => 4200, 'waveform' => [0, 40, 90, 12]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.is_voice', true)
            ->assertJsonPath('data.attachments.0.duration_ms', 4200)
            ->assertJsonPath('data.attachments.0.waveform', [0, 40, 90, 12]);
    }

    /** Обычное вложение остаётся обычным: волны и длительности у него нет. */
    public function test_an_ordinary_attachment_is_not_a_recording(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->post(route('chat.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('прайс.pdf', 20, 'application/pdf')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.is_voice', false)
            ->assertJsonPath('data.attachments.0.duration_ms', null)
            ->assertJsonPath('data.attachments.0.waveform', []);
    }

    /** Числами записи нельзя подписать не запись. */
    public function test_a_document_cannot_pretend_to_be_a_recording(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->post(route('chat.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('архив.zip', 20, 'application/zip')],
                'voice' => ['duration_ms' => 4200, 'waveform' => [10, 20]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('voice');
    }

    /** Трёхчасового «голосового» не бывает: длительность подрезается. */
    public function test_an_absurd_length_is_clamped(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->post(route('chat.messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('voice.webm', 40, 'audio/webm')],
                'voice' => ['duration_ms' => 3 * 60 * 60 * 1000, 'waveform' => [10]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.duration_ms', 300_000);
    }

    public function test_a_mention_is_remembered(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($me, [$mate]);

        $id = $this->actingAs($me)
            ->postJson(route('chat.messages.store', $group), [
                'body' => 'Посмотришь отчёт?',
                'mentions' => [$mate->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame([$mate->id], Message::query()->findOrFail($id)->mentions);
    }

    /** Позвать постороннего нельзя: он в этом разговоре не состоит. */
    public function test_an_outsider_cannot_be_mentioned(): void
    {
        $me = $this->employee();
        $group = $this->groupOf($me, [$this->employee()]);
        $stranger = $this->employee();

        $id = $this->actingAs($me)
            ->postJson(route('chat.messages.store', $group), [
                'body' => 'Позвал бы, да нельзя',
                'mentions' => [$stranger->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(Message::query()->findOrFail($id)->mentions);
    }

    /** Позванных считают отдельно: сорок непрочитанных и вопрос лично тебе — разное. */
    public function test_mentions_are_counted_apart_from_the_unread(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $group = $this->groupOf($other, [$me]);

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $group), ['body' => 'Общее'])
            ->assertCreated();

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $group), [
                'body' => 'А это тебе',
                'mentions' => [$me->id],
            ])
            ->assertCreated();

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.unread_mentions', 1);
    }
}
