<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeCategory>
 */
final class KnowledgeCategoryFactory extends Factory
{
    protected $model = KnowledgeCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->optional()->sentence(),
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
