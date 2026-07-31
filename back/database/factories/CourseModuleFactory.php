<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CourseModule>
 */
final class CourseModuleFactory extends Factory
{
    protected $model = CourseModule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => Str::ucfirst(fake()->words(3, true)),
            'description' => fake()->optional()->sentence(),
            'position' => 0,
        ];
    }
}
