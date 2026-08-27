<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Regulation>
 */
final class RegulationFactory extends Factory
{
    protected $model = Regulation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->words(4, true));

        return [
            'author_id' => User::factory(),
            'category_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'summary' => fake()->sentence(),
            'content_json' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => fake()->paragraph()]],
                ]],
            ],
            'status' => CourseStatus::Draft,
            'visibility' => CourseVisibility::Public,
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Published,
            'published_at' => now(),
        ]);
    }

    /** Закрытый: виден автору, допущенным и суперадминистратору. */
    public function closed(): self
    {
        return $this->state(fn (): array => ['visibility' => CourseVisibility::Private]);
    }
}
