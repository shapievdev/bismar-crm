<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Models\OnboardingJourneyStage;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Лучший балл прошлых участников по тому же тесту — без имён.
 *
 * «Тот же тест» теперь не одна строка: у каждого человека своя копия, снятая
 * с трафарета. Сравниваются копии одного трафарета (`source_stage_id`) — это и
 * есть «тот же тест», как его понимает человек. У этапа, придуманного для
 * одного (трафарета нет), сравнивать не с чем, и это честный `null`.
 *
 * Мотивация, а не доска почёта: имя рядом с баллом превратило бы цифру в
 * сравнение людей, чего ТЗ прямо избегает. Свои попытки исключаются — «побей
 * свой рекорд» экран считает из собственной истории.
 */
final readonly class BestScore
{
    public function of(OnboardingJourneyStage $stage, Quiz $quiz, User $excluding): ?int
    {
        if ($stage->source_stage_id === null) {
            return null;
        }

        $best = QuizAttempt::query()
            ->whereIn('quiz_id', $this->quizzesFromTheSameStencil($stage))
            ->where('user_id', '!=', $excluding->getKey())
            ->max('score');

        return $best === null ? null : (int) $best;
    }

    /**
     * Тесты всех этапов, снятых с того же трафарета.
     *
     * @return Builder
     */
    private function quizzesFromTheSameStencil(OnboardingJourneyStage $stage)
    {
        return DB::table('quizzes')
            ->join('onboarding_journey_stages', function ($join): void {
                $join->on('onboarding_journey_stages.id', '=', 'quizzes.quizzable_id')
                    ->where('quizzes.quizzable_type', '=', 'onboarding_journey_stage');
            })
            ->where('onboarding_journey_stages.source_stage_id', $stage->source_stage_id)
            ->when($stage->template_version !== null, fn ($q) => $q->where('onboarding_journey_stages.template_version', $stage->template_version))
            ->select('quizzes.id');
    }
}
