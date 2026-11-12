<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExamStatus;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exam>
 */
final class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Экзамен по продукту',
            'slug' => 'exam-'.Str::lower(Str::random(8)),
            'description' => null,
            // Опубликованный и бессрочный: большинству тестов нужен экзамен,
            // который можно сдавать, а не черновик.
            'status' => ExamStatus::Published,
            'opens_at' => null,
            'closes_at' => null,
            'time_limit_minutes' => null,
            'shuffle_questions' => false,
            'created_by' => null,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => ExamStatus::Draft]);
    }

    public function archived(): self
    {
        return $this->state(['status' => ExamStatus::Archived]);
    }
}
