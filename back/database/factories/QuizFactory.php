<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Regulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
final class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Владелец по умолчанию — урок: с него тесты и начались. Тест
            // документа заводят явно, через forRegulation().
            'quizzable_type' => 'lesson',
            'quizzable_id' => Lesson::factory(),
            'title' => 'Проверка знаний',
            'description' => null,
            // Планка теста при уроке — правило, а не настройка автора: урок
            // зачитывается при всех верных ответах.
            'passing_score' => Quiz::PASSING_SCORE,
            'max_attempts' => null,
        ];
    }

    /** Тест при этом уроке. */
    public function forLesson(Lesson $lesson): self
    {
        return $this->state([
            'quizzable_type' => $lesson->getMorphClass(),
            'quizzable_id' => $lesson->getKey(),
        ]);
    }

    /** Тест при документе, а не при уроке. */
    public function forRegulation(Regulation $regulation): self
    {
        return $this->state([
            'quizzable_type' => $regulation->getMorphClass(),
            'quizzable_id' => $regulation->getKey(),
        ]);
    }

    /**
     * Adds single-choice questions where the first option is the correct one,
     * so tests can submit a known-good or known-bad answer.
     */
    public function withQuestions(int $count = 2): self
    {
        return $this->afterCreating(function (Quiz $quiz) use ($count): void {
            foreach (range(0, $count - 1) as $position) {
                $question = $quiz->questions()->create([
                    'text' => "Вопрос {$position}",
                    'type' => QuestionType::Single,
                    'points' => 1,
                    'position' => $position,
                ]);

                $question->options()->create(['text' => 'Верно', 'is_correct' => true, 'position' => 0]);
                $question->options()->create(['text' => 'Неверно', 'is_correct' => false, 'position' => 1]);
            }
        });
    }
}
