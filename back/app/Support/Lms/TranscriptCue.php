<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Одна реплика расшифровки: что сказано и где именно.
 *
 * Место бывает трёх видов, и у каждой расшифровки своё: у записи — секунда, у
 * документа — страница, у статьи — абзац. Заполнено всегда не больше одного:
 * реплика не может быть одновременно на двенадцатой минуте и на четвёртой
 * странице.
 */
final readonly class TranscriptCue
{
    public function __construct(
        public string $text,
        public ?int $startsAt = null,
        public ?int $page = null,
        public ?string $blockId = null,
    ) {}

    /** Та же реплика с проставленным абзацем. */
    public function inBlock(?string $blockId): self
    {
        return new self($this->text, $this->startsAt, $this->page, $blockId);
    }
}
