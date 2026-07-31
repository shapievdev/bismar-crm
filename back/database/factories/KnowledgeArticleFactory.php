<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArticleStatus;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeArticle>
 */
final class KnowledgeArticleFactory extends Factory
{
    protected $model = KnowledgeArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->words(4, true));

        return [
            'category_id' => KnowledgeCategory::factory(),
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => fake()->optional()->sentence(),
            'content' => fake()->paragraphs(3, true),
            'status' => ArticleStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published,
            'published_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Archived,
            'published_at' => now()->subMonths(2),
        ]);
    }
}
