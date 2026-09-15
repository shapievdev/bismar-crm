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
use App\Support\Chat\VoiceRecording;
use App\Support\Push\PushMessage;
use Illuminate\Contracts\Database\Query\Builder;
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
     * @param  array<string, string|null>|null  $about  материал, с которого написали,
     *                                                  снимком — см. MaterialAppeal
     * @param  list<int>  $mentions  кого назвали по имени; посторонние отсеиваются здесь же
     * @param  VoiceRecording|null  $voice  если реплика — надиктованная запись
     */
    public function handle(
        Conversation $conversation,
        User $author,
        ?string $body,
        array $files = [],
        ?int $replyToId = null,
        ?array $about = null,
        array $mentions = [],
        ?VoiceRecording $voice = null,
    ): Message {
        $body = $body === null ? null : trim($body);
        $called = $this->onlyParticipants($conversation, $mentions);

        $message = DB::transaction(function () use ($conversation, $author, $body, $files, $replyToId, $about, $called, $voice): Message {
            $message = $conversation->messages()->create([
                'user_id' => $author->getKey(),
                'reply_to_id' => $replyToId,
                'kind' => MessageKind::Text,
                'body' => $body === '' ? null : $body,
                'about' => $about,
                'mentions' => $called === [] ? null : $called,
            ]);

            foreach ($files as $at => $file) {
                // Имя объекта выбирает Laravel: пришедшее от клиента годится
                // только для показа.
                $path = $file->store('chat/'.$conversation->getKey(), self::DISK);

                // Надиктованная запись всегда одна и всегда первая: записывают
                // её отдельной кнопкой, вместе с файлами не отправляют.
                $spoken = $voice !== null && $at === 0;

                $message->attachments()->create([
                    'disk' => self::DISK,
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'is_voice' => $spoken,
                    'duration_ms' => $spoken ? $voice->durationMs : null,
                    'waveform' => $spoken ? $voice->waveform : null,
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
     * Оставляет из названных тех, кто в переписке действительно состоит.
     *
     * Список приходит от клиента, а значит, в нём может оказаться кто угодно —
     * и уведомление о разговоре, к которому человек не допущен, рассказало бы
     * ему и о разговоре, и о том, что в нём сказали.
     *
     * @param  list<int>  $mentions
     * @return list<int>
     */
    private function onlyParticipants(Conversation $conversation, array $mentions): array
    {
        $wanted = array_values(array_unique(array_map(intval(...), $mentions)));

        if ($wanted === []) {
            return [];
        }

        return $conversation->activeParticipants()
            ->whereKey($wanted)
            ->pluck('users.id')
            ->map(intval(...))
            ->values()
            ->all();
    }

    /**
     * Уведомление на устройство — тем, кто в переписке состоит, кроме автора.
     *
     * Живое время у сообщения своё, Reverb: открытая вкладка покажет реплику
     * сама. Push нужен закрытой — и очередь берёт его на себя, чтобы отправка
     * не задерживала ответ тому, кто пишет.
     *
     * Приглушивший разговор уведомлений не получает — кроме случая, когда его
     * назвали по имени. Приглушают обсуждение, а не себя: человек не хочет
     * слышать двадцать реплик про обед, но вопрос, заданный лично ему, обязан
     * дойти — иначе упоминание в приглушённой группе не значит ничего, и
     * приглушать перестают вовсе.
     */
    private function notify(Conversation $conversation, User $author, Message $message): void
    {
        $called = $this->calledIn($message);

        $recipients = $conversation->activeParticipants()
            ->whereKeyNot($author->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNull('conversation_participants.muted_at')
                ->when($called !== [], fn (Builder $mentioned) => $mentioned->orWhereIn('users.id', $called)))
            ->pluck('users.id')
            ->map(intval(...))
            ->all();

        if ($recipients === []) {
            return;
        }

        // В группе называют и её, и говорящего: «Иванов» из ниоткуда не
        // говорит человеку, куда идти отвечать.
        $title = $conversation->isGroup()
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
     * Кого назвали по имени в этой реплике.
     *
     * @return list<int>
     */
    private function calledIn(Message $message): array
    {
        $called = $message->mentions;

        return is_array($called)
            ? array_values(array_unique(array_map(intval(...), $called)))
            : [];
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
