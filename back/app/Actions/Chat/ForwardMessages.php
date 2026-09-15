<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Enums\MessageKind;
use App\Events\Chat\MessageSent;
use App\Jobs\SendPush;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Chat\Announcement;
use App\Support\Lms\StoredFiles;
use App\Support\Push\PushMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Пересылает сказанное в другой разговор.
 *
 * Копией, а не ссылкой. Ссылка означала бы, что реплика показывается в чужой
 * переписке по праву доступа к той, из которой её взяли, — и живёт ровно до
 * того дня, когда исходную удалят. Пересылают же именно затем, чтобы сказанное
 * дошло в третий разговор и там осталось.
 *
 * Отсюда и копия файла: два вложения на один объект в хранилище означают, что
 * удаление любого из сообщений отнимает файл у второго — а удаление, в отличие
 * от пересылки, необратимо.
 *
 * Подпись «переслано от» берётся снимком (см. миграцию): имя автора и день,
 * когда это было сказано, — то самое, ради чего пересылают. Исходная реплика с
 * тех пор может быть переписана, удалена, а автор уволен.
 */
final readonly class ForwardMessages
{
    /** Куда складывать копии: тот же бакет, что у своих вложений. */
    private const DISK = 's3';

    /**
     * @param  Collection<int, Message>  $messages  в том порядке, в каком их сказали
     * @return list<Message> пересланное, в том же порядке
     */
    public function handle(Collection $messages, Conversation $target, User $actor): array
    {
        $forwarded = [];

        foreach ($messages as $source) {
            $forwarded[] = $this->one($source, $target, $actor);
        }

        if ($forwarded === []) {
            return [];
        }

        $this->notify($target, $actor, count($forwarded));

        return $forwarded;
    }

    /**
     * Одна реплика: сначала копии файлов, потом запись.
     *
     * Именно в таком порядке. Копирование в хранилище не участвует в
     * транзакции, и сорвавшись после записи, оно оставило бы в переписке
     * сообщение с вложением, которого нет. Обратный порядок оставляет в худшем
     * случае осиротевший объект — его уберёт правило жизненного цикла бакета.
     */
    private function one(Message $source, Conversation $target, User $actor): Message
    {
        $copies = $this->copyFiles($source);

        try {
            $message = DB::transaction(function () use ($source, $target, $actor, $copies): Message {
                $message = $target->messages()->create([
                    'user_id' => $actor->getKey(),
                    'kind' => MessageKind::Text,
                    'body' => $source->body,

                    /*
                     * Ни ответа, ни карточки материала, ни упоминаний.
                     *
                     * Цитата указывала бы на реплику из другого разговора,
                     * которой здесь нет. Карточка материала названа снимком
                     * закрытого документа — она уходила бы к тому, кому сам
                     * документ не показывают. Упомянутые в исходной переписке
                     * могут в этой не состоять, и звать их сюда нельзя.
                     */
                    'forwarded' => $this->origin($source),
                ]);

                foreach ($copies as $copy) {
                    $message->attachments()->create($copy);
                }

                $target->forceFill(['last_message_at' => $message->created_at])->save();

                // Переслав, ты это прочёл: иначе своё же появлялось бы у тебя
                // непрочитанным.
                $target->participants()->updateExistingPivot($actor->getKey(), [
                    'last_read_at' => $message->created_at,
                ]);

                return $message;
            });
        } catch (Throwable $exception) {
            // Запись не удалась — копии остались бы висеть в бакете ничьими.
            foreach ($copies as $copy) {
                StoredFiles::discard(self::DISK, $copy['path']);
            }

            throw $exception;
        }

        Announcement::attempt(new MessageSent($message->load(['author', 'attachments'])));

        return $message;
    }

    /**
     * Откуда это пришло — снимком.
     *
     * У уже пересланной реплики берётся её собственный снимок, а не тот, кто
     * переслал: пересылка передаёт первоисточник, и цепочка из трёх рук не
     * должна подменять автора последним звеном.
     *
     * @return array<string, string|int|null>
     */
    private function origin(Message $source): array
    {
        if (is_array($source->forwarded) && $source->forwarded !== []) {
            /** @var array<string, string|int|null> $earlier */
            $earlier = $source->forwarded;

            return $earlier;
        }

        return [
            'author_id' => $source->user_id,
            'author_name' => $source->author?->name ?? 'Бывший сотрудник',
            'said_at' => $source->created_at?->toIso8601String(),
        ];
    }

    /**
     * Копии вложений в хранилище — вместе с тем, что о них известно.
     *
     * @return list<array<string, mixed>>
     */
    private function copyFiles(Message $source): array
    {
        $copies = [];

        foreach ($source->attachments as $attachment) {
            $path = $this->copyOne($attachment, $copies);

            $copies[] = [
                'disk' => self::DISK,
                'path' => $path,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,

                // Голосовое остаётся голосовым и в чужой переписке — вместе с
                // волной и длительностью, считать которые заново неоткуда.
                'is_voice' => $attachment->isVoice(),
                'duration_ms' => $attachment->duration_ms,
                'waveform' => $attachment->waveform,
            ];
        }

        return $copies;
    }

    /**
     * @param  list<array<string, mixed>>  $done  уже сделанные копии — их убирают, если эта не удалась
     */
    private function copyOne(MessageAttachment $attachment, array $done): string
    {
        // Своё имя объекта, а не прежнее: копия живёт своей жизнью, и совпади
        // они, удаление исходного сообщения стёрло бы файл у пересланного.
        $path = 'chat/forwarded/'.Str::uuid()->toString().'/'.basename($attachment->path);

        try {
            Storage::disk($attachment->disk)->copy($attachment->path, $path);
        } catch (Throwable $exception) {
            foreach ($done as $copy) {
                StoredFiles::discard(self::DISK, $copy['path']);
            }

            throw new RuntimeException('Вложение не удалось переслать.', previous: $exception);
        }

        return $path;
    }

    /**
     * Уведомление на устройство — одно на всю пересылку, а не на каждую реплику.
     *
     * Переслать десять сообщений разом — обычное дело, и десять уведомлений
     * подряд означали бы, что мессенджером после этого пользоваться неприятно.
     */
    private function notify(Conversation $target, User $actor, int $count): void
    {
        $recipients = $target->activeParticipants()
            ->whereKeyNot($actor->getKey())
            ->pluck('users.id')
            ->map(intval(...))
            ->all();

        if ($recipients === []) {
            return;
        }

        $title = $target->isGroup()
            ? sprintf('%s · %s', (string) $target->title, $actor->name)
            : $actor->name;

        SendPush::dispatch($recipients, new PushMessage(
            title: $title,
            body: $count === 1 ? 'Переслал сообщение' : sprintf('Переслал сообщений: %d', $count),
            url: '/messenger?id='.$target->getKey(),
            tag: 'conversation-'.$target->getKey(),
        ));
    }
}
