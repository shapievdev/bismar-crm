<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            // Родителя ставит тест: отдел без него — это компания, а она в
            // структуре одна и заведена вместе с базой.
            'parent_id' => null,
            'position' => 0,
        ];
    }
}
