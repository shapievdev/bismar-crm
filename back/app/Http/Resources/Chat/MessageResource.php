<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Message;
use App\Support\Chat\MaterialCard;
use App\Support\Chat\ReactionSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'kind' => $this->kind->value,
            'body' => $this->body,

            // У системного сообщения автора нет, у обычного он мог уволиться:
            // в обоих случаях подписи не будет, и это разные вещи только для
            // того, кто пишет код, — читателю всё равно.
            'author' => PersonResource::make($this->whenLoaded('author')),

            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),

            // Пустое поле, а не отсутствие: «не правили» — это тоже ответ, и
            // приложению не нужно догадываться, загружали ли признак.
            'edited_at' => $this->edited_at?->toIso8601String(),

            'reply_to' => $this->quotedReply(),

            // Материал, с которого написали, — карточкой над репликой.
            'about' => $this->materialCard(),

            // Откуда переслано: имя автора и день, когда это было сказано.
            // Снимком — исходной реплики может уже не быть.
            'forwarded' => $this->forwardedFrom(),

            /*
             * Отклики — набором, а не «мой и остальные».
             *
             * Тот же набор уходит в эфир всем участникам сразу, и вычислять в
             * нём «своё» значило бы рассылать по сообщению на человека. Вкладка
             * узнаёт себя по номерам откликнувшихся — см. ReactionSummary.
             */
            'reactions' => $this->relationLoaded('reactions')
                ? ReactionSummary::of($this->resource)
                : [],

            // Поднято ли наверх переписки.
            'pinned_at' => $this->pinned_at?->toIso8601String(),
        ];
    }

    /**
     * Подпись «переслано от».
     *
     * Пустое поле приводится к `null`, а не отдаётся как есть: пустой массив в
     * JSON выглядит объектом, и экран проверял бы его на длину вместо того,
     * чтобы спросить, есть ли подпись вообще.
     *
     * @return array<string, mixed>|null
     */
    private function forwardedFrom(): ?array
    {
        $origin = $this->forwarded;

        return is_array($origin) && $origin !== [] ? $origin : null;
    }

    /**
     * Карточка «с какого материала это письмо».
     *
     * Рисуется тем же кодом, что и карточка над полем ввода: адресат должен
     * увидеть ровно то, что видел отправитель, когда писал.
     *
     * @return array<string, string|null>|null
     */
    private function materialCard(): ?array
    {
        return MaterialCard::render($this->about);
    }

    /**
     * Цитата над ответом: ровно столько, сколько нужно, чтобы узнать реплику.
     *
     * Целиком её не отдаём — в ленте уже лежит она сама, а в цитате длинный
     * текст всё равно обрезается. Удалённая цитата приходит помеченной и без
     * текста: показать надо, что отвечали на что-то, чего больше нет.
     *
     * @return array<string, mixed>|null
     */
    private function quotedReply(): ?array
    {
        if ($this->reply_to_id === null || ! $this->relationLoaded('replyTo')) {
            return null;
        }

        $original = $this->replyTo;

        if ($original === null) {
            return null;
        }

        return [
            'id' => $original->getKey(),
            'deleted' => $original->trashed(),
            // Как и у самой реплики, подписи может не быть: у системной её
            // нет вовсе, а автора обычной могли удалить из системы.
            'author' => $original->relationLoaded('author') && $original->author !== null
                ? PersonResource::make($original->author)
                : null,
            'excerpt' => $original->trashed() ? null : $this->excerptOf($original),
        ];
    }

    /**
     * Короткая выжимка чужой реплики. Сообщение из одних вложений текста не
     * имеет, и вместо пустоты цитата называет, что там лежало.
     */
    private function excerptOf(Message $original): string
    {
        $body = trim((string) $original->body);

        if ($body !== '') {
            return mb_strimwidth($body, 0, 120, '…');
        }

        $count = $original->relationLoaded('attachments')
            ? $original->attachments->count()
            : $original->attachments()->count();

        return $count > 0 ? 'Вложение' : '';
    }
}
