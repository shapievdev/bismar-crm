<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AnswerSource;

/**
 * Место внутри урока, куда ведёт ссылка на источник.
 *
 * Ради этого таблица и заводилась. Ссылки на урок мало: урок бывает на десять
 * экранов текста и на час записи, и «проверьте сами» превращается в поиск
 * глазами. Здесь сказано, на какой секунде, на какой странице и в каком абзаце.
 */
final readonly class SourceLocation
{
    public function __construct(
        public AnswerSource $kind,
        public ?int $seconds = null,
        public ?int $page = null,
        public ?string $blockId = null,
        public ?string $attachmentName = null,
        /** Подписанная ссылка на файл — живёт столько же, сколько все прочие. */
        public ?string $attachmentUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'label' => $this->label(),
            'seconds' => $this->seconds,
            'page' => $this->page,
            'block_id' => $this->blockId,
            'attachment_name' => $this->attachmentName,
            'attachment_url' => $this->attachmentUrl,
        ];
    }

    /**
     * Подпись, которую читатель видит на карточке источника.
     *
     * Место называется словами, а не полями: «видео, 12:35» понятнее, чем вид
     * источника и число рядом.
     */
    public function label(): string
    {
        return match ($this->kind) {
            AnswerSource::Video => $this->seconds === null
                ? 'Видео урока'
                : 'Видео урока, '.self::timecode($this->seconds),
            AnswerSource::Attachment => $this->page === null
                ? ($this->attachmentName ?? 'Приложенный файл')
                : sprintf('%s, стр. %d', $this->attachmentName ?? 'Приложенный файл', $this->page),
            AnswerSource::Text => 'Текст урока',
        };
    }

    /**
     * Секунды как «мм:сс», а на записях длиннее часа — «ч:мм:сс».
     */
    public static function timecode(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }
}
