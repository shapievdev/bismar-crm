<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;

/**
 * Разбор попытки: что человек выбрал и где ошибся.
 *
 * Без него тест только преграда. Сотрудник получает «68%, не сдано» и идёт
 * проходить заново с тем же знанием, с каким пришёл, — а пересдача без разбора
 * учит разве что перебирать варианты.
 *
 * Разбор строится по нынешнему тесту, а ответы хранятся снимком выбранных
 * вариантов. Вопрос, добавленный после попытки, поэтому числится
 * неотвеченным — это правда: его и не показывали.
 */
final readonly class QuizReview
{
    /**
     * Разбор попытки для того, кто её проходил.
     *
     * @return array<string, mixed>
     */
    public function of(QuizAttempt $attempt, User $learner): array
    {
        $quiz = $attempt->quiz;

        $quiz->loadMissing('questions.options');

        $revealsKey = $this->revealsKey($attempt, $quiz, $learner);

        /** @var array<int|string, list<int>> $submitted */
        $submitted = $attempt->answers ?? [];

        $questions = $quiz->questions->map(
            fn (QuizQuestion $question): array => $this->question(
                $question,
                array_map(intval(...), $submitted[$question->getKey()] ?? $submitted[(string) $question->getKey()] ?? []),
                $revealsKey,
            ),
        )->values()->all();

        return [
            // Раскрыт ли верный ответ. Экран по этому решает, показывать
            // разбор целиком или только «здесь неверно».
            'reveals_key' => $revealsKey,
            'questions' => $questions,
        ];
    }

    /**
     * Показывать ли верные ответы.
     *
     * Пока попытки остались, — нет. Иначе тест с неограниченным числом попыток
     * проходится так: отправить пустое, посмотреть ключ, отправить его обратно.
     * Что именно отвечено неверно, человек видит в любом случае: это говорит,
     * куда вернуться в уроке, и ничего не выдаёт.
     *
     * Сдал или попытки кончились — скрывать больше нечего и незачем.
     */
    private function revealsKey(QuizAttempt $attempt, Quiz $quiz, User $learner): bool
    {
        return $attempt->passed || ! $quiz->hasAttemptsLeft($learner);
    }

    /**
     * @param  list<int>  $selected
     * @return array<string, mixed>
     */
    private function question(QuizQuestion $question, array $selected, bool $revealsKey): array
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();
        $chosen = collect($selected)->unique()->sort()->values()->all();

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'type' => $question->type->value,
            'points' => $question->points,

            // Считается той же меркой, что и балл: вариант в вариант, без
            // частичного зачёта, — иначе разбор расходился бы с оценкой.
            'is_correct' => $correct === $chosen && $correct !== [],
            'is_answered' => $chosen !== [],
            'selected_option_ids' => $chosen,

            'options' => $question->options->map(fn ($option): array => [
                'id' => $option->id,
                'text' => $option->text,
                'is_chosen' => in_array((int) $option->id, $chosen, strict: true),
                // Ключ уходит только вместе с правом его видеть: собрать его
                // из разбора должно быть так же нельзя, как из самого теста.
                'is_correct' => $revealsKey ? (bool) $option->is_correct : null,
            ])->values()->all(),
        ];
    }
}
