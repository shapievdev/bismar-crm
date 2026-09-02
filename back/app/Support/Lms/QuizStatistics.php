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
            // Именами список людей обрастает здесь же: попытки всё равно
            // читаются целиком, и второй запрос за теми же сотрудниками из
            // экрана ничего бы не убрал.
            ->with('user:id,last_name,first_name,middle_name')
            // Читается целиком, потому что попытки одного теста — это в худшем
            // случае несколько на сотрудника. Если счёт пойдёт на десятки
            // тысяч, считать придётся в базе.
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'score', 'passed', 'answers', 'scores', 'completed_at']);

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

            'people' => $this->people($attempts),
        ];
    }

    /**
     * Кто проходил тест и что отправлял.
     *
     * Доли по вопросам говорят о материале, но не о людях: за «сдали трое из
     * пяти» стоят двое, которым урок не дался, и найти их можно только
     * поимённо. Попытки приложены все, а не первые: с ними разговор о том, что
     * человек так и не понял с третьего раза, ведётся по фактам — разбор каждой
     * открывается отсюда же.
     *
     * Вперёд идут не сдавшие: список читают, чтобы решить, с кем сесть и
     * разобрать материал, а не чтобы полюбоваться сдавшими.
     *
     * @param  Collection<int, QuizAttempt>  $attempts
     * @return list<array<string, mixed>>
     */
    private function people(Collection $attempts): array
    {
        return $attempts
            ->groupBy('user_id')
            ->map(function (Collection $own): ?array {
                $learner = $own->first()?->user;

                if ($learner === null) {
                    return null;
                }

                return [
                    'id' => $learner->getKey(),
                    'name' => $learner->name,
                    'passed' => $own->contains('passed', true),
                    'best_score' => (int) $own->max('score'),

                    'attempts' => $own->map(fn (QuizAttempt $attempt): array => [
                        'id' => $attempt->getKey(),
                        'score' => (int) $attempt->score,
                        'passed' => (bool) $attempt->passed,
                        'completed_at' => $attempt->completed_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })
            ->filter()
            ->sortBy(fn (array $person): string => ($person['passed'] ? '1' : '0').mb_strtolower($person['name']))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, QuizAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function question(QuizQuestion $question, Collection $attempts): array
    {
        if ($question->type->isWritten() || $question->type->isTable()) {
            return $this->openQuestion($question, $attempts);
        }

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

    /**
     * Вопрос без вариантов: письменный ответ или таблица.
     *
     * Вместо «какой вариант выбирают» здесь средняя схожесть с эталоном — она и
     * говорит автору о пороге: если верные по смыслу ответы стоят у самой
     * черты, дело не в людях, а в том, что эталон написан слишком узко. У
     * таблицы схожести нет, зато есть доля заполнивших её целиком.
     *
     * @param  Collection<int, QuizAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function openQuestion(QuizQuestion $question, Collection $attempts): array
    {
        $answered = 0;
        $right = 0;
        $similarities = [];

        foreach ($attempts as $attempt) {
            /** @var array<int|string, mixed> $submitted */
            $submitted = $attempt->answers ?? [];
            /** @var array<int|string, array<string, mixed>> $scores */
            $scores = $attempt->scores ?? [];

            $key = $question->getKey();
            $given = $submitted[$key] ?? $submitted[(string) $key] ?? null;

            // Тронут ли вопрос: у письменного это непустая строка, у таблицы —
            // хоть одна заполненная ячейка.
            $touched = is_array($given)
                ? collect($given)->flatten()->contains(fn ($cell): bool => trim((string) $cell) !== '')
                : trim((string) $given) !== '';

            if (! $touched) {
                continue;
            }

            $answered++;

            $score = $scores[$key] ?? $scores[(string) $key] ?? [];

            if (($score['points'] ?? 0) > 0) {
                $right++;
            }

            if (isset($score['similarity'])) {
                $similarities[] = (float) $score['similarity'];
            }
        }

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'answered' => $answered,
            'correct' => $right,
            'correct_share' => $answered === 0 ? null : (int) round($right / $answered * 100),

            'average_similarity' => $similarities === []
                ? null
                : round(array_sum($similarities) / count($similarities), 2),

            'options' => [],
        ];
    }
}
