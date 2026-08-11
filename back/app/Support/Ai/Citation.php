<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Источник в том виде, в каком его видит читатель.
 *
 * Достаточно опознания, чтобы дойти до материала и проверить утверждение, — в
 * этом весь смысл ссылки. Верить консультанту на слово читателю не предлагается.
 */
final readonly class Citation
{
    public function __construct(
        public int $lessonId,
        public string $lessonTitle,
        public string $courseTitle,
        public string $courseSlug,
        /** Что именно было прочитано: кусок текста или готовый ответ. */
        public string $quote,
        /** Вопрос строки таблицы — виден, только если ответ пришёл оттуда. */
        public ?string $question = null,
        public ?SourceLocation $location = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lesson_id' => $this->lessonId,
            'lesson_title' => $this->lessonTitle,
            'course_title' => $this->courseTitle,
            'course_slug' => $this->courseSlug,
            'quote' => $this->quote,
            'question' => $this->question,
            'location' => $this->location?->toArray(),
        ];
    }
}
