<?php

declare(strict_types=1);

namespace App\Data\Knowledge;

use App\Enums\ArticleStatus;

final readonly class ArticleData
{
    public function __construct(
        public string $title,
        public string $content,
        public ArticleStatus $status,
        public ?string $excerpt = null,
        public ?int $categoryId = null,
    ) {}

    /**
     * @param  array{title: string, content: string, status: string, excerpt?: string|null, category_id?: int|null}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            title: $validated['title'],
            content: $validated['content'],
            status: ArticleStatus::from($validated['status']),
            excerpt: $validated['excerpt'] ?? null,
            categoryId: $validated['category_id'] ?? null,
        );
    }
}
