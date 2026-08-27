<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<News>
 */
final class NewsFactory extends Factory
{
    protected $model = News::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->words(4, true));

        return [
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => fake()->sentence(),
            'content_json' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => fake()->paragraph()]],
                ]],
            ],
            'status' => NewsStatus::Draft,
            'published_at' => null,
            'is_pinned' => false,
            'audience' => NewsAudience::Everyone,
            'requires_acknowledgement' => false,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => NewsStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function pinned(): self
    {
        return $this->state(fn (): array => ['is_pinned' => true]);
    }

    /** Адресная: список людей проставляет тест, ему виднее кого. */
    public function addressed(): self
    {
        return $this->state(fn (): array => ['audience' => NewsAudience::Selected]);
    }

    public function mustBeAcknowledged(): self
    {
        return $this->state(fn (): array => ['requires_acknowledgement' => true]);
    }
}
