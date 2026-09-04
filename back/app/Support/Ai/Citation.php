<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Источник в том виде, в каком его видит читатель.
 *
 * Достаточно опознания, чтобы дойти до материала и проверить утверждение, — в
 * этом весь смысл ссылки. Верить консультанту на слово читателю не предлагается.
 *
 * Материалов два рода: урок внутри курса и документ, который сам себе целое.
 * Общих полей у них меньше, чем разных, поэтому вместо одного набора с
 * половиной пустых значений — два способа собрать ссылку и один способ её
 * прочесть.
 */
final readonly class Citation
{
    public const LESSON = 'lesson';

    public const DOCUMENT = 'document';

    private function __construct(
        public string $kind,
        /** Номер материала: урока или документа. */
        public int $materialId,
        /** Название материала — то, что читатель увидит на карточке. */
        public string $title,
        /** Курс, внутри которого лежит урок. У документа его нет. */
        public ?string $courseTitle,
        public ?string $courseSlug,
        public ?string $documentSlug,
        /** Что именно было прочитано: кусок текста или готовый ответ. */
        public string $quote,
        /** Вопрос строки таблицы — виден, только если ответ пришёл оттуда. */
        public ?string $question = null,
        public ?SourceLocation $location = null,
    ) {}

    public static function forLesson(
        int $lessonId,
        string $lessonTitle,
        string $courseTitle,
        string $courseSlug,
        string $quote,
        ?string $question = null,
        ?SourceLocation $location = null,
    ): self {
        return new self(
            kind: self::LESSON,
            materialId: $lessonId,
            title: $lessonTitle,
            courseTitle: $courseTitle,
            courseSlug: $courseSlug,
            documentSlug: null,
            quote: $quote,
            question: $question,
            location: $location,
        );
    }

    public static function forDocument(
        int $documentId,
        string $title,
        string $slug,
        string $quote,
        ?string $question = null,
        ?SourceLocation $location = null,
    ): self {
        return new self(
            kind: self::DOCUMENT,
            materialId: $documentId,
            title: $title,
            courseTitle: null,
            courseSlug: null,
            documentSlug: $slug,
            quote: $quote,
            question: $question,
            location: $location,
        );
    }

    /**
     * Чем один материал отличается от другого.
     *
     * По этому ключу схлопываются советы «смотрите также»: два куска одного
     * урока ведут читателя в одно место, и второй ему сказать нечего. Номера
     * самого по себе мало — урок №3 и документ №3 разные вещи.
     */
    public function materialKey(): string
    {
        return $this->kind.'-'.$this->materialId;
    }

    /**
     * Адрес страницы материала.
     *
     * Собирается здесь, а не на экране: правил всего два, и держать их в двух
     * местах значит однажды поправить одно.
     */
    public function url(): string
    {
        return $this->kind === self::DOCUMENT
            ? '/lms/documents/'.$this->documentSlug
            : sprintf('/lms/%s/lessons/%d', $this->courseSlug, $this->materialId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $common = [
            'kind' => $this->kind,
            'title' => $this->title,
            'url' => $this->url(),
            'quote' => $this->quote,
            'question' => $this->question,
            'location' => $this->location?->toArray(),
        ];

        if ($this->kind === self::DOCUMENT) {
            return [...$common, 'document_id' => $this->materialId, 'document_slug' => $this->documentSlug];
        }

        /*
         * Урочные поля остаются под прежними именами: ими записаны все ссылки
         * в журнале вопросов, а он читается годами. Новый ключ `kind` их не
         * отменяет — у старых записей его просто нет, и экран считает такую
         * ссылку урочной.
         */
        return [
            ...$common,
            'lesson_id' => $this->materialId,
            'lesson_title' => $this->title,
            'course_title' => $this->courseTitle,
            'course_slug' => $this->courseSlug,
        ];
    }
}
