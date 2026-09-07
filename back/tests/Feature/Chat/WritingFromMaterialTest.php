<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationKind;
use App\Models\Message;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * «Написать» с карточки ответственного: письмо уносит с собой материал.
 *
 * С документа уходят в мессенджер, и без карточки над репликой адресат читает
 * вопрос, не понимая, о чём он. Карточка та же, что и у замечаний, только без
 * причины: здесь спрашивают, а не жалуются.
 */
final class WritingFromMaterialTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Сообщение рассылает уведомления; здесь проверяют не их.
        Queue::fake();
    }

    private function document(User $author): Regulation
    {
        return Regulation::factory()->published()->create([
            'author_id' => $author->id,
            'title' => 'Возврат клиенту',
        ]);
    }

    /** Переписка на двоих — та, в которую ведёт «Написать». */
    private function conversationBetween(User $reader, User $recipient): int
    {
        return (int) $this->actingAs($reader)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Direct->value,
                'user_id' => $recipient->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_a_message_carries_the_material_it_was_written_from(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $document = $this->document($author);

        $conversation = $this->conversationBetween($reader, $author);

        $this->actingAs($reader)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'Как быть, если чек потерян?',
                'about' => ['kind' => 'document', 'id' => $document->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.about.kind_label', 'Документ')
            ->assertJsonPath('data.about.title', 'Возврат клиенту')
            ->assertJsonPath('data.about.url', $document->path())
            // Причины нет: с карточки ответственного спрашивают, а не жалуются.
            ->assertJsonPath('data.about.reason', null)
            ->assertJsonPath('data.about.reason_label', null);

        $this->assertSame('document', Message::query()->latest('id')->firstOrFail()->about['kind']);
    }

    /**
     * Название и адрес собирает сервер: карточкой над репликой нельзя объявить
     * то, чего в материале нет.
     */
    public function test_the_card_is_built_from_the_material_not_from_the_request(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $document = $this->document($author);

        $conversation = $this->conversationBetween($reader, $author);

        $this->actingAs($reader)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'Вопрос.',
                'about' => [
                    'kind' => 'document',
                    'id' => $document->id,
                    'title' => 'Приказ об увольнении',
                    'url' => '/lms/documents/postoronnee',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.about.title', 'Возврат клиенту')
            ->assertJsonPath('data.about.url', $document->path());
    }

    /**
     * Закрытый чужой материал в разговор не попадает даже названием: письмо
     * уходит, карточки у него нет.
     */
    public function test_a_material_the_writer_cannot_see_leaves_no_card(): void
    {
        $reader = $this->learner();
        $author = $this->author();
        $document = Regulation::factory()->published()->closed()->create([
            'author_id' => $author->id,
            'title' => 'Оклады отдела',
        ]);

        $conversation = $this->conversationBetween($reader, $author);

        $this->actingAs($reader)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'Вопрос.',
                'about' => ['kind' => 'document', 'id' => $document->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.about', null);
    }

    /** Выброшенный или несуществующий материал разговор не срывает. */
    public function test_a_missing_material_still_sends_the_message(): void
    {
        $reader = $this->learner();
        $recipient = $this->author();

        $conversation = $this->conversationBetween($reader, $recipient);

        $this->actingAs($reader)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'Вопрос.',
                'about' => ['kind' => 'document', 'id' => 9999],
            ])
            ->assertCreated()
            ->assertJsonPath('data.about', null);
    }

    /** Экран спрашивает карточку до отправки — чтобы показать её над полем ввода. */
    public function test_the_card_is_readable_before_the_message_is_sent(): void
    {
        $reader = $this->learner();
        $document = $this->document($this->author());

        $this->actingAs($reader)
            ->getJson(route('chat.about', ['kind' => 'document', 'id' => $document->id]))
            ->assertOk()
            ->assertJsonPath('data.kind_label', 'Документ')
            ->assertJsonPath('data.title', 'Возврат клиенту');
    }

    /** Чужого закрытого материала для спрашивающего не существует. */
    public function test_a_closed_material_has_no_card_to_read(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->closed()->create([
            'author_id' => $this->author()->id,
        ]);

        $this->actingAs($reader)
            ->getJson(route('chat.about', ['kind' => 'document', 'id' => $document->id]))
            ->assertNotFound();
    }

    /** Вид сверяется с найденным: документ, названный справочником, не карточка. */
    public function test_a_mislabelled_kind_finds_nothing(): void
    {
        $reader = $this->learner();
        $document = $this->document($this->author());

        $this->actingAs($reader)
            ->getJson(route('chat.about', ['kind' => 'handbook', 'id' => $document->id]))
            ->assertNotFound();
    }
}
