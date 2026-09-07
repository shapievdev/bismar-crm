<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Enums\AppealReason;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * Как называется то, с чего написали. Раздел важен: «документ» и
     * «справочник» — разные места, и искать правило среди справок читателю не
     * предлагается.
     */
    private const KIND_LABELS = [
        'course' => 'Курс',
        'lesson' => 'Урок',
        'document' => 'Документ',
        'handbook' => 'Справочник',
    ];

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
        ];
    }

    /**
     * Карточка «с какого материала это замечание».
     *
     * Названия и адрес лежат снимком со дня отправки, а подписи собираются
     * сейчас: переименуй мы кнопку «Ответа не хватило», старые сообщения
     * должны читаться новыми словами — они об одном и том же.
     *
     * @return array<string, string|null>|null
     */
    private function materialCard(): ?array
    {
        $about = $this->about;

        if (! is_array($about) || ! isset($about['kind'], $about['title'])) {
            return null;
        }

        $reason = AppealReason::tryFrom((string) ($about['reason'] ?? ''));

        return [
            'kind' => (string) $about['kind'],
            'kind_label' => self::KIND_LABELS[(string) $about['kind']] ?? 'Материал',
            'title' => (string) $about['title'],
            // Курс, внутри которого лежит урок: одно название урока не
            // говорит, где его искать.
            'context' => $about['context'] === null ? null : (string) $about['context'],
            'url' => $about['url'] === null ? null : (string) $about['url'],
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
        ];
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
