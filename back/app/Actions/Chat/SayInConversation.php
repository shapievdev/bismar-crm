<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Enums\MessageKind;
use App\Events\Chat\MessageSent;
use App\Jobs\SendPush;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Chat\Announcement;
use App\Support\Push\PushMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Кладёт сообщение в переписку и разносит его собеседникам.
 *
 * Одно место на оба вида сообщений — сказанное человеком и отмеченное системой:
 * лента у них общая, порядок общий, и рассылка та же самая. Разница только в
 * том, есть ли автор.
 */
final readonly class SayInConversation
{
    /** Куда складывать вложения переписки. */
    private const DISK = 's3';

    /**
     * @param  list<UploadedFile>  $files
     * @param  int|null  $replyToId  реплика, на которую отвечают; её принадлежность
     *                               этой же переписке проверяет SendMessageRequest
     */
    public function handle(
        Conversation $conversation,
        User $author,
        ?string $body,
        array $files = [],
        ?int $replyToId = null,
    ): Message {
        $body = $body === null ? null : trim($body);

        $message = DB::transaction(function () use ($conversation, $author, $body, $files, $replyToId): Message {
            $message = $conversation->messages()->create([
                'user_id' => $author->getKey(),
                'reply_to_id' => $replyToId,
                'kind' => MessageKind::Text,
                'body' => $body === '' ? null : $body,
            ]);

            foreach ($files as $file) {
                // Имя объекта выбирает Laravel: пришедшее от клиента годится
                // только для показа.
                $path = $file->store('chat/'.$conversation->getKey(), self::DISK);

                $message->attachments()->create([
                    'disk' => self::DISK,
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            // Отправив сообщение, ты его прочёл: иначе собственная реплика
            // висела бы у тебя же непрочитанной.
            $conversation->participants()->updateExistingPivot($author->getKey(), [
                'last_read_at' => $message->created_at,
            ]);

            return $message;
        });

        Announcement::attempt(new MessageSent($message->load(['author', 'attachments', 'replyTo.author'])));

        $this->notify($conversation, $author, $message);

        return $message;
    }

    /**
     * Уведомление на устройство — тем, кто в переписке состоит, кроме автора.
     *
     * Живое время у сообщения своё, Reverb: открытая вкладка покажет реплику
     * сама. Push нужен закрытой — и очередь берёт его на себя, чтобы отправка
     * не задерживала ответ тому, кто пишет.
     */
    private function notify(Conversation $conversation, User $author, Message $message): void
    {
        $recipients = $conversation->activeParticipants()
            ->whereKeyNot($author->getKey())
            ->pluck('users.id')
            ->map(intval(...))
            ->all();

        if ($recipients === []) {
            return;
        }

        // В группе называют и её, и говорящего: «Иванов» из ниоткуда не
        // говорит человеку, куда идти отвечать.
        $title = $conversation->is_group
            ? sprintf('%s · %s', (string) $conversation->title, $author->name)
            : $author->name;

        SendPush::dispatch($recipients, new PushMessage(
            title: $title,
            body: PushMessage::shorten($message->body) ?: 'Вложение',
            url: '/messenger?id='.$conversation->getKey(),
            // Одно уведомление на переписку: десять реплик подряд заменяют
            // друг друга, а не выстраиваются столбиком на весь экран.
            tag: 'conversation-'.$conversation->getKey(),
        ));
    }

    /**
     * Системная отметка: «добавил», «вышел», «переименовал».
     *
     * Текст записывается готовым, а не собирается при чтении: участник мог с
     * тех пор смениться или уволиться, а сказано было то, что сказано.
     */
    public function system(Conversation $conversation, string $text): Message
    {
        $message = DB::transaction(function () use ($conversation, $text): Message {
            $message = $conversation->messages()->create([
                'kind' => MessageKind::System,
                'body' => $text,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        Announcement::attempt(new MessageSent($message->load('attachments')));

        return $message;
    }
}
