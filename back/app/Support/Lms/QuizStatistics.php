<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Support\Collection;

/**
 * Что тест показывает автору урока.
 *
 * Вопрос, который заваливают почти все, — обычно не признак того, что люди
 * невнимательны, а признак того, что в уроке об этом либо не сказано, либо
 * сказано так, что понять нельзя. Такой вопрос — единственное место, где урок
 * сам сообщает о своей дыре, и стоит он ровно ничего: ответы уже хранятся.
 *
 * Считается по первым попыткам каждого человека. Вторая и третья испорчены
 * тем, что он уже видел разбор: по ним вопрос выглядит лёгким, каким бы плохо
 * написанным он ни был.
 */
final readonly class QuizStatistics
{
    /**
     * @return array<string, mixed>
     */
    public function of(Quiz $quiz): array
    {
        $quiz->loadMissing('questions.options');

        $attempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->getKey())
            // Читается целиком, потому что попытки одного теста — это в худшем
            // случае несколько на сотрудника. Если счёт пойдёт на десятки
            // тысяч, считать придётся в базе.
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'score', 'passed', 'answers']);

        $first = $attempts->unique('user_id')->values();

        return [
            'attempts' => $attempts->count(),
            'learners' => $first->count(),

            // Сдавших считаем по людям, а не по попыткам: сдавший с третьего
            // раза — сдавший, и в доле он должен стоять один раз.
            'passed' => $attempts->where('passed', true)->unique('user_id')->count(),

            // Средний балл — по первым попыткам, по той же причине, по какой
            // и разбор вопросов: он должен говорить о материале, а не о том,
            // сколько раз люди пересдавали.
            'average_first_score' => $first->isEmpty() ? null : (int) round($first->avg('score')),

            'questions' => $quiz->questions->map(
                fn (QuizQuestion $question): array => $this->question($question, $first),
            )->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, QuizAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function question(QuizQuestion $question, Collection $attempts): array
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();

        $answered = 0;
        $right = 0;

        /** @var array<int, int> $chosenTimes */
        $chosenTimes = [];

        foreach ($attempts as $attempt) {
            /** @var array<int|string, list<int>> $submitted */
            $submitted = $attempt->answers ?? [];

            $key = $question->getKey();
            $selected = $submitted[$key] ?? $submitted[(string) $key] ?? [];
            $selected = collect($selected)->map(intval(...))->unique()->sort()->values()->all();

            if ($selected === []) {
                continue;
            }

            $answered++;

            if ($selected === $correct) {
                $right++;
            }

            foreach ($selected as $optionId) {
                $chosenTimes[$optionId] = ($chosenTimes[$optionId] ?? 0) + 1;
            }
        }

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'answered' => $answered,
            'correct' => $right,

            // Доля верных — по отвечавшим, а не по всем сдававшим: вопрос,
            // который пропустили, ничего о себе не сообщает.
            'correct_share' => $answered === 0 ? null : (int) round($right / $answered * 100),

            'options' => $question->options->map(fn ($option): array => [
                'id' => $option->id,
                'text' => $option->text,
                'is_correct' => (bool) $option->is_correct,
                // Сколько раз выбран каждый вариант. Неверный вариант, который
                // выбирают чаще верного, — самая говорящая строка в разборе:
                // в уроке написано что-то, что читается именно так.
                'chosen' => $chosenTimes[(int) $option->id] ?? 0,
            ])->values()->all(),
        ];
    }
}
