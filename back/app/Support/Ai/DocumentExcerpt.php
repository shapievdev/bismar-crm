<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Str;

/**
 * Кусок документа, который консультанту позволено цитировать.
 *
 * То же самое, что LessonExcerpt, но у документа нет курса: он сам себе целое —
 * правило, по которому работают. Отдельный класс, а не пустые поля в общем:
 * модели он подаётся другими словами, и читателю ведёт по другому адресу.
 */
final readonly class DocumentExcerpt implements Excerpt
{
    public function __construct(
        public int $documentId,
        /** Кусок расшифровки, из которого взят текст — по нему находится вектор. */
        public int $segmentId,
        public string $title,
        public string $slug,
        public string $text,
        public ?SourceLocation $location = null,
    ) {}

    /**
     * Как кусок подаётся модели.
     *
     * «Документ», а не «курс → урок»: модель должна понимать, что перед ней
     * правило, а не учебный материал, — от этого зависит и тон ответа, и то,
     * насколько буквально его следует пересказывать.
     */
    public function toPrompt(int $number): string
    {
        return sprintf(
            "[источник %d] Документ «%s»%s\n%s",
            $number,
            $this->title,
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
        return Citation::forDocument(
            documentId: $this->documentId,
            title: $this->title,
            slug: $this->slug,
            // Тот самый кусок, из которого взят ответ: ссылки на документ мало,
            // правило бывает на десять страниц.
            quote: Str::limit(preg_replace('/\s+/u', ' ', $this->text) ?? '', 240),
            location: $this->location,
        );
    }
}
