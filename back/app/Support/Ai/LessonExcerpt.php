<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Str;

/**
 * One piece of course material the consultant is allowed to quote, with enough
 * identity attached that the reader can go and check it.
 *
 * Кусок расшифровки: реплика из записи, абзац документа или блок статьи. Место
 * при нём такое же точное, как у строки таблицы, — секунда, страница, абзац.
 */
final readonly class LessonExcerpt implements Excerpt
{
    public function __construct(
        public int $lessonId,
        /** Кусок расшифровки, из которого взят текст — по нему находится вектор. */
        public int $segmentId,
        public string $lessonTitle,
        public string $courseTitle,
        public string $courseSlug,
        public string $text,
        public ?SourceLocation $location = null,
    ) {}

    /**
     * How the excerpt is presented to the model.
     *
     * Numbered by its place in the list rather than by the lesson's id. Given
     * ids, the model reads them as ordinary small numbers and writes «источник
     * 2» for the second fragment it was shown — a citation of a real lesson
     * that was never supplied, and one the caller can only throw away. With
     * four fragments numbered one to four there is nothing left to guess.
     */
    public function toPrompt(int $number): string
    {
        return sprintf(
            "[источник %d] Курс «%s» → урок «%s»%s\n%s",
            $number,
            $this->courseTitle,
            $this->lessonTitle,
            // Откуда именно взято. Модели это нужно не для ссылки — ссылку
            // ставит приложение, — а чтобы она не выдавала сказанное в записи
            // за написанное в регламенте.
            $this->location === null ? '' : ' ('.$this->location->label().')',
            $this->text,
        );
    }

    public function segment(): int
    {
        return $this->segmentId;
    }

    public function key(): string
    {
        return 'segment:'.$this->segmentId;
    }

    public function citation(): Citation
    {
        return Citation::forLesson(
            lessonId: $this->lessonId,
            lessonTitle: $this->lessonTitle,
            courseTitle: $this->courseTitle,
            courseSlug: $this->courseSlug,
            // Тот самый кусок урока, из которого взят ответ. Ссылки на урок
            // мало: урок бывает на десять экранов, и «проверьте сами»
            // превращается в поиск глазами. Здесь видно место сразу.
            quote: Str::limit(preg_replace('/\s+/u', ' ', $this->text) ?? '', 240),
            location: $this->location,
        );
    }
}
