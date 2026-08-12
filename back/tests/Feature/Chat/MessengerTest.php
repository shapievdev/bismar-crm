<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationKind;
use App\Enums\MessageKind;
use App\Events\Chat\MessageSent;
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
 * Переписка сотрудников между собой.
 *
 * Проверяется не «сокеты работают» — за это отвечает Reverb, — а то, что
 * приложение рассылает нужное нужным и не пускает посторонних: сокет-сервер сам
 * ничего не знает ни о людях, ни о переписках и верит только подписи, которую
 * ставит приложение.
 */
final class MessengerTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Личная переписка у пары одна: второй раз попадаешь в тот же разговор. */
    public function test_a_direct_conversation_is_created_once_for_a_pair(): void
    {
        $me = $this->employee();
        $other = $this->employee();

        $first = $this->actingAs($me)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Direct->value,
                'user_id' => $other->id,
            ])
            ->assertCreated()
            ->json('data.id');

        // Второй заход — хоть с другой стороны: разговор тот же.
        $second = $this->actingAs($other)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Direct->value,
                'user_id' => $me->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::query()->count());
    }

    /**
     * Имя личной переписки — имя собеседника, у каждого своё.
     *
     * И короткое: полное ФИО в заголовок не помещается и обрезается на середине
     * фамилии. Полное при этом остаётся при собеседнике — оно нужно там, где
     * человека называют официально.
     */
    public function test_a_direct_conversation_is_named_after_the_other_person(): void
    {
        $me = $this->employee();
        $other = User::factory()->create([
            'first_name' => 'Пётр',
            'last_name' => 'Петров',
            'middle_name' => 'Иванович',
        ]);

        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data.title', 'Пётр П. И.')
            ->assertJsonPath('data.companion.id', $other->id)
            ->assertJsonPath('data.companion.name', 'Петров Пётр Иванович')
            ->assertJsonPath('data.companion.short_name', 'Пётр П. И.');

        $this->actingAs($other)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data.title', $me->short_name);
    }

    /** Без фамилии и отчества сокращать нечего — остаётся одно имя. */
    public function test_a_short_name_falls_back_to_the_first_name_alone(): void
    {
        $me = $this->employee();
        $other = User::factory()->create([
            'first_name' => 'Саида',
            'last_name' => null,
            'middle_name' => null,
        ]);

        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->getJson(route('chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data.title', 'Саида');
    }

    /** Отправленное попадает в ленту и уходит в эфир. */
    public function test_a_message_is_stored_and_broadcast(): void
    {
        Event::fake([MessageSent::class]);

        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Привет, есть минута?'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Привет, есть минута?')
            ->assertJsonPath('data.author.id', $me->id);

        Event::assertDispatched(MessageSent::class);

        // Список сортируется по времени последнего сказанного — значит, оно
        // должно быть записано.
        $this->assertNotNull($conversation->refresh()->last_message_at);
    }

    /** В чужую переписку не заглянуть и не написать. */
    public function test_a_stranger_can_neither_read_nor_write(): void
    {
        $conversation = $this->conversationBetween($this->employee(), $this->employee());
        $stranger = $this->employee();

        $this->actingAs($stranger)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Подслушиваю'])
            ->assertForbidden();

        $this->assertSame(0, $conversation->messages()->count());
    }

    /**
     * И подписаться на её канал — тоже.
     *
     * Единственная преграда здесь — подпись, которую ставит приложение:
     * сокет-сервер сам не знает ни людей, ни переписок и верит только ей.
     * Поэтому проверяется на настоящем вещателе: в тестах вещание отключено
     * (BROADCAST_CONNECTION=null), а пустой вещатель пускает кого угодно, и
     * такая проверка не проверяла бы ничего.
     */
    public function test_only_participants_may_subscribe_to_a_conversation_channel(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->withRealBroadcaster();

        // socket_id настоящий клиент присылает всегда: подпись выдаётся
        // конкретному соединению, а не вообще.
        $channel = [
            'channel_name' => 'private-conversations.'.$conversation->id,
            'socket_id' => '1234.5678',
        ];

        $this->actingAs($this->employee())
            ->postJson('/broadcasting/auth', $channel)
            ->assertForbidden();

        $this->actingAs($me)
            ->postJson('/broadcasting/auth', $channel)
            ->assertOk();
    }

    /** Чужой личный канал — тем более чужой. */
    public function test_a_personal_channel_belongs_to_one_person(): void
    {
        $me = $this->employee();
        $other = $this->employee();

        $this->withRealBroadcaster();

        $this->actingAs($me)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-users.'.$other->id,
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();

        $this->actingAs($me)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-users.'.$me->id,
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    /** Непрочитанное считается по чужим сообщениям и гаснет по прочтении. */
    public function test_unread_is_counted_and_cleared(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Первое'])
            ->assertCreated();
        $this->actingAs($other)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Второе'])
            ->assertCreated();

        $this->actingAs($me)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 2);

        // У написавшего своё непрочитанным не висит.
        $this->actingAs($other)
            ->getJson(route('chat.unread'))
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->actingAs($me)->postJson(route('chat.conversations.read', $conversation))->assertOk();

        $this->actingAs($me)
            ->getJson(route('chat.unread'))
            ->assertOk()
            ->assertJsonPath('data.unread', 0);
    }

    /** Группа заводится с отметкой в ленте — иначе непонятно, откуда она. */
    public function test_a_group_is_created_with_a_note_in_the_thread(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();

        $id = $this->actingAs($owner)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Group->value,
                'title' => 'Розничный отдел',
                'user_ids' => [$mate->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Розничный отдел')
            ->assertJsonPath('data.is_group', true)
            ->assertJsonPath('data.participants_count', 2)
            ->json('data.id');

        $system = Message::query()->where('conversation_id', $id)->sole();

        $this->assertSame(MessageKind::System, $system->kind);
        $this->assertStringContainsString('создал группу', (string) $system->body);
    }

    /** Состав группы ведёт тот, кто её завёл. */
    public function test_only_the_owner_changes_the_group(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $newcomer = $this->employee();

        $group = $this->groupOf($owner, [$mate]);

        $this->actingAs($mate)
            ->postJson(route('chat.participants.store', $group), ['user_ids' => [$newcomer->id]])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('chat.participants.store', $group), ['user_ids' => [$newcomer->id]])
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertStringContainsString(
            'добавил в группу',
            (string) $group->messages()->latest('id')->first()?->body,
        );
    }

    /** Вышедший больше не в составе, но его сообщения остаются. */
    public function test_leaving_a_group_keeps_what_was_said(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $this->actingAs($mate)
            ->postJson(route('chat.messages.store', $group), ['body' => 'Сказанное до ухода'])
            ->assertCreated();

        $this->actingAs($mate)->postJson(route('chat.conversations.leave', $group))->assertOk();

        $this->assertFalse($group->refresh()->includes($mate));
        $this->assertSame(1, $group->messages()->where('kind', MessageKind::Text)->count());

        // И читать её он больше не может: вышел — значит вышел.
        $this->actingAs($mate)
            ->getJson(route('chat.messages.index', $group))
            ->assertForbidden();

        // Список переписок у него тоже чист.
        $this->actingAs($mate)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Файл прикладывается к сообщению и отдаётся подписанной ссылкой. */
    public function test_a_file_can_be_attached_to_a_message(): void
    {
        Storage::fake('s3');

        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'Вот прайс',
                'attachments' => [UploadedFile::fake()->create('прайс.pdf', 64, 'application/pdf')],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.name', 'прайс.pdf');

        $this->assertSame(1, Storage::disk('s3')->allFiles() === [] ? 0 : 1);
    }

    /** Пустое сообщение отправить нельзя. */
    public function test_an_empty_message_is_rejected(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->actingAs($me)
            ->postJson(route('chat.messages.store', $conversation), ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    /** Лента читается кусками от конца. */
    public function test_the_thread_is_read_backwards_in_pages(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        foreach (range(1, 45) as $number) {
            $conversation->messages()->create([
                'user_id' => $other->id,
                'kind' => MessageKind::Text,
                'body' => 'Сообщение '.$number,
            ]);
        }

        $page = $this->actingAs($me)
            ->getJson(route('chat.messages.index', $conversation))
            ->assertOk()
            ->assertJsonCount(40, 'data')
            ->json('data');

        // Свежие — в конце: разговор читается сверху вниз.
        $this->assertSame('Сообщение 45', $page[39]['body']);

        $older = $this->actingAs($me)
            ->getJson(route('chat.messages.index', ['conversation' => $conversation->id, 'before' => $page[0]['id']]))
            ->assertOk()
            ->json('data');

        $this->assertSame('Сообщение 1', $older[0]['body']);
    }

    /* ---------- helpers ---------- */

    /**
     * Поднимает настоящий вещатель и заново объявляет ему каналы.
     *
     * Каналы объявляются при загрузке приложения тому вещателю, который в тот
     * момент был выбран; подменив его, нужно объявить их снова, иначе он не
     * знает ни одного и отказывает всем.
     */
    private function withRealBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }

    private function employee(): User
    {
        return User::factory()->create();
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
            'title' => 'Группа',
            'created_by_id' => $owner->id,
        ]);

        $group->participants()->attach([$owner->id, ...array_map(static fn (User $one): int => (int) $one->id, $mates)]);

        return $group;
    }
}
