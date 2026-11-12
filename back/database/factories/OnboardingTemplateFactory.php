<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DayMode;
use App\Enums\OnboardingTemplateStatus;
use App\Models\OnboardingTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OnboardingTemplate>
 */
final class OnboardingTemplateFactory extends Factory
{
    protected $model = OnboardingTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Маршрут продавца-консультанта',
            'slug' => 'route-'.Str::lower(Str::random(8)),
            'description' => null,
            // Опубликованный: большинству тестов нужен шаблон, по которому
            // можно назначать, а не черновик.
            'status' => OnboardingTemplateStatus::Published,
            'duration_days' => 7,
            'day_mode' => DayMode::Calendar,
            'allow_ahead' => false,
            'meeting_reminder_minutes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => OnboardingTemplateStatus::Draft]);
    }

    public function workingDays(): self
    {
        return $this->state(['day_mode' => DayMode::Working]);
    }

    /**
     * Шаблон с N этапами по дню на этап: день 1, день 2, …
     */
    public function withStages(int $count = 3): self
    {
        return $this->afterCreating(function (OnboardingTemplate $template) use ($count): void {
            foreach (range(1, $count) as $day) {
                $template->stages()->create([
                    'position' => $day,
                    'day_offset' => $day,
                    'title' => "Этап {$day}",
                    'description' => null,
                ]);
            }
        });
    }
}
