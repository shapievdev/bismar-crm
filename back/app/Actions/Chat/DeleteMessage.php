<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\MessageDeleted;
use App\Models\Message;
use App\Support\Chat\Announcement;
use Illuminate\Support\Facades\DB;

/**
 * Убирает реплику у всех.
 *
 * Удаление одностороннее — «удалить у себя» здесь нет намеренно: переписка
 * сотрудников не личная переписка, и разные ленты у собеседников означали бы,
 * что на вопрос «а где то сообщение» двое смотрят в разные разговоры.
 *
 * Строка остаётся, содержимое уходит. Уходит по-настоящему: текст обнуляется, а
 * вложения стираются и из базы, и из хранилища — удалённое не должно лежать в
 * базе и открываться по ссылке. Остаётся голая строка, и нужна она только тем,
 * кто на эту реплику отвечал: без неё их «да, согласен» повисает без того, с чем
 * соглашались.
 */
final readonly class DeleteMessage
{
    public function handle(Message $message): void
    {
        $attachments = $message->attachments()->get();

        DB::transaction(function () use ($message, $attachments): void {
            foreach ($attachments as $attachment) {
                $attachment->delete();
            }

            $message->forceFill(['body' => null])->save();
            $message->delete();
        });

        /*
         * Файлы — после транзакции. Хранилище не участвует в откате: сорвавшееся
         * удаление объекта не должно возвращать сообщение в ленту, а осиротевший
         * объект дешевле (см. StoredFiles).
         */
        foreach ($attachments as $attachment) {
            $attachment->deleteFromStorage();
        }

        Announcement::attempt(new MessageDeleted($message));
    }
}
