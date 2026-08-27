<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RegulationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RegulationCategory>
 */
final class RegulationCategoryFactory extends Factory
{
    protected $model = RegulationCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::ucfirst(fake()->unique()->words(2, true));

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => null,
            'position' => 0,
        ];
    }
}
