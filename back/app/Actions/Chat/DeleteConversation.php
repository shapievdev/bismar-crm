<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\ConversationRemoved;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\Chat\Announcement;
use Illuminate\Support\Facades\DB;

/**
 * Убирает переписку у всех — насовсем.
 *
 * Здесь удаление настоящее, а не пометка: разговор кончился, и держать его
 * строками в базе незачем. Уходят и сообщения, и участие, и приложенные файлы —
 * первое и второе внешними ключами (`cascadeOnDelete`), файлы из хранилища
 * приходится убирать самим: о нём база не знает.
 *
 * У личной переписки это право есть у обоих: разговор двоих принадлежит двоим.
 * У группы — только у того, кто её завёл; остальные из неё выходят. Кто именно
 * — решает ConversationPolicy, а не это место.
 */
final readonly class DeleteConversation
{
    public function handle(Conversation $conversation): void
    {
        // Собираем до удаления: после него не останется ни строк вложений, ни
        // строк участия, а нужны и те и другие.
        $attachments = MessageAttachment::query()
            ->whereIn('message_id', Message::withTrashed()
                ->where('conversation_id', $conversation->getKey())
                ->select('id'))
            ->get();

        /** @var list<int> $recipients */
        $recipients = $conversation->participants()
            ->pluck('users.id')
            ->map(intval(...))
            ->all();

        DB::transaction(static fn () => $conversation->delete());

        /*
         * Файлы — после транзакции, как и при удалении одной реплики: хранилище
         * в откате не участвует, и сорвавшееся удаление объекта не должно
         * возвращать переписку к жизни. Осиротевший объект дешевле (StoredFiles).
         */
        foreach ($attachments as $attachment) {
            $attachment->deleteFromStorage();
        }

        Announcement::attempt(new ConversationRemoved((int) $conversation->getKey(), $recipients));
    }
}
