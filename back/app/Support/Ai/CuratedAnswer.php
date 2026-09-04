<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Str;

/**
 * Строка таблицы урока, отобранная поиском.
 *
 * В отличие от LessonExcerpt это не найденный кусок текста, а написанная
 * человеком пара «вопрос — ответ» с указанием места. Модели она подаётся такой,
 * какая есть: пересказывать выверенную формулировку незачем и опасно.
 */
final readonly class CuratedAnswer implements Source
{
    public function __construct(
        public int $answerId,
        public int $lessonId,
        public string $lessonTitle,
        public string $courseTitle,
        public string $courseSlug,
        public string $question,
        public string $answer,
        public SourceLocation $location,
        /** Близость к заданному вопросу: по ней решается дословная выдача. */
        public float $score = 0.0,
    ) {}

    public function toPrompt(int $number): string
    {
        return sprintf(
            "[источник %d] Курс «%s» → урок «%s»\nВопрос: %s\nОтвет: %s",
            $number,
            $this->courseTitle,
            $this->lessonTitle,
            $this->question,
            $this->answer,
        );
    }

    public function key(): string
    {
        return 'answer:'.$this->answerId;
    }

    public function citation(): Citation
    {
        return Citation::forLesson(
            lessonId: $this->lessonId,
            lessonTitle: $this->lessonTitle,
            courseTitle: $this->courseTitle,
            courseSlug: $this->courseSlug,
            // Ответ целиком, а не обрезок: он для того и написан, чтобы его
            // прочли. Обрезается лишь совсем длинный, где карточка перестаёт
            // быть карточкой.
            quote: Str::limit(preg_replace('/\s+/u', ' ', $this->answer) ?? '', 600),
            question: $this->question,
            location: $this->location,
        );
    }

    /** Та же строка с проставленной оценкой. */
    public function scored(float $score): self
    {
        return new self(
            $this->answerId,
            $this->lessonId,
            $this->lessonTitle,
            $this->courseTitle,
            $this->courseSlug,
            $this->question,
            $this->answer,
            $this->location,
            $score,
        );
    }
}
