<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Str;

/**
 * Урок, названный по имени, — не найденный ответ, а совет, куда посмотреть.
 *
 * От LessonExcerpt отличается тем, чего у него нет: куска текста. Сюда доходят
 * вопросы, по которым в расшифровках не нашлось ничего, и назвать место внутри
 * урока нечем — известно лишь, что урок и курс называются про это.
 *
 * Поэтому в промпт он идёт с прямой оговоркой, что ответа в нём не приведено:
 * получив название и описание курса, слабая модель охотно сочиняет по ним
 * содержание урока.
 */
final readonly class LessonSuggestion implements Source
{
    public function __construct(
        public int $lessonId,
        public string $lessonTitle,
        public string $courseTitle,
        public string $courseSlug,
        /** Описание курса — всё, что о содержании известно наверняка. */
        public string $summary = '',
    ) {}

    public function toPrompt(int $number): string
    {
        return sprintf(
            '[источник %d] Курс «%s» → урок «%s». Содержание урока не приведено, известно только название.%s',
            $number,
            $this->courseTitle,
            $this->lessonTitle,
            $this->summary === '' ? '' : ' О курсе: '.$this->summary,
        );
    }

    public function key(): string
    {
        return 'lesson:'.$this->lessonId;
    }

    public function citation(): Citation
    {
        return Citation::forLesson(
            lessonId: $this->lessonId,
            lessonTitle: $this->lessonTitle,
            courseTitle: $this->courseTitle,
            courseSlug: $this->courseSlug,
            // Описание курса, а не выдумка про урок: читателю показывают
            // ровно то, чем этот совет обоснован.
            quote: Str::limit(preg_replace('/\s+/u', ' ', $this->summary) ?? '', 240),
        );
    }
}
