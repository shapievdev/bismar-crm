<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Lms\GradeQuizAttempt;
use App\Enums\JourneyStatus;
use App\Enums\StageStatus;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourneyStage;
use App\Models\QuizAttempt;
use App\Support\Onboarding\StageRequirements;

/**
 * Сдача теста этапа: проверка, что этап открыт, предел с учётом добавленных
 * попыток — и та же оценка, что у тестов уроков.
 *
 * Запертый этап не принимает работ: обойти карту прямым запросом нельзя — на
 * то она и карта, а не картинка.
 */
final readonly class SubmitStageQuiz
{
    public function __construct(
        private GradeQuizAttempt $grade,
        private StageRequirements $requirements,
        private RefreshJourneyProgress $refresh,
    ) {}

    /**
     * @param  array<int, list<int>|list<list<string>>|string>  $answers
     *
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyStage $stage, array $answers): QuizAttempt
    {
        $journey = $stage->loadMissing('journey.user', 'quiz.questions.options')->journey;

        if ($journey->status !== JourneyStatus::Active) {
            throw new ConflictException('Маршрут не идёт — сдавать некуда.');
        }

        $quiz = $stage->quiz;

        if ($quiz === null || $quiz->questions->isEmpty()) {
            throw new ConflictException('У этого этапа нет теста.');
        }

        $state = $this->requirements->of($journey)[(int) $stage->getKey()] ?? null;

        if ($state === null || $state->status === StageStatus::Locked) {
            throw new ConflictException('Этап ещё закрыт — тест ждёт своего дня.');
        }

        // Предел — правило теста плюс добавленные этому стажёру попытки:
        // о добавке знает этап журнала, и только он.
        $limit = $quiz->max_attempts === null ? null : $quiz->max_attempts + $stage->extra_attempts;

        $attempt = $this->grade->handle($quiz, $journey->user, $answers, null, $limit);

        $this->refresh->handle($journey);

        return $attempt;
    }
}
