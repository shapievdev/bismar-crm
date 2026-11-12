<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingStage>
 */
final class OnboardingStageFactory extends Factory
{
    protected $model = OnboardingStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => OnboardingTemplate::factory(),
            'position' => 1,
            'day_offset' => 1,
            'title' => 'Компания: история и ценности',
            'description' => null,
        ];
    }
}
