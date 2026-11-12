<?php

declare(strict_types=1);

namespace App\Support\Exams;

use App\Enums\AttestationStatus;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Как люди сдают экзамен — по строке на назначенного.
 *
 * Одна и та же сводка нужна двоим, и считаться обязана одинаково: автору — по
 * всем назначенным, сотруднику — по себе в списке «мои экзамены». «Сдал» здесь
 * значит «есть зачтённая попытка», и лучшая попытка показывается лучшей по
 * баллам, а не последней: пересдача хуже прежнего результата его не отменяет.
 */
final readonly class ExamResults
{
    /**
     * Сводка по каждому назначенному. Люди без единой попытки — тоже строки:
     * автору важнее всего ровно те, кто ещё не садился.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function for(Exam $exam): Collection
    {
        $exam->loadMissing('quiz', 'assignments.user');

        $attempts = $exam->quiz === null
            ? collect()
            : $exam->quiz->attempts()->get()->groupBy('user_id');

        return $exam->assignments
            ->sortBy(fn ($assignment) => mb_strtolower((string) $assignment->user?->name))
            ->values()
            ->map(function ($assignment) use ($attempts): array {
                $own = $attempts->get($assignment->user_id, collect());

                return [
                    'user' => $assignment->user === null ? null : [
                        'id' => $assignment->user->getKey(),
                        'name' => $assignment->user->name,
                    ],
                    'due_at' => $assignment->due_at,
                    ...$this->summarize($own, $assignment->due_at !== null && $assignment->due_at->isPast()),
                ];
            });
    }

    /**
     * Та же строка — про одного человека. Для «моих экзаменов».
     *
     * @return array<string, mixed>
     */
    public function summaryFor(?Quiz $quiz, User $sitter, bool $overdue = false): array
    {
        $own = $quiz === null
            ? collect()
            : $quiz->attempts()->where('user_id', $sitter->getKey())->get();

        return $this->summarize($own, $overdue);
    }

    /**
     * @param  Collection<int, QuizAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function summarize(Collection $attempts, bool $dueIsPast): array
    {
        $best = $attempts->sortByDesc('score')->first();
        $passed = $attempts->contains(fn (QuizAttempt $attempt): bool => $attempt->passed);

        return [
            'attempts_spent' => $attempts->count(),
            'best_score' => $best?->score,
            'passed' => $passed,
            // Работа у проверяющего: сдавшему — «ждите», автору — «не торопите».
            'awaiting_review' => $attempts->contains(
                fn (QuizAttempt $attempt): bool => $attempt->review_status === AttestationStatus::Pending,
            ),
            'last_attempt_at' => $attempts->max('completed_at'),
            // Просрочен — срок прошёл, а зачтённой попытки нет. Сданное в срок
            // не делается просроченным от того, что срок миновал.
            'overdue' => $dueIsPast && ! $passed,
        ];
    }
}
