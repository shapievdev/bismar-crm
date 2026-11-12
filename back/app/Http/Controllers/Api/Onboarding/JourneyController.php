<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Actions\Onboarding\OpenJourney;
use App\Actions\Onboarding\RefreshJourneyProgress;
use App\Actions\Onboarding\SubmitStageQuiz;
use App\Actions\Onboarding\SubmitTask;
use App\Enums\StageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SubmitQuizRequest;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyMeeting;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingJourneyTask;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\QuizReview;
use App\Support\Onboarding\AssignedStages;
use App\Support\Onboarding\JourneyPresenter;
use App\Support\Onboarding\StageRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Маршрут глазами его хозяина.
 *
 * Права на маршрутах нет: маршрут и так свой — отбор всюду идёт по вошедшему,
 * чужой не открыть ни одним номером. Чтение попутно пересчитывает прогресс:
 * материал, пройденный в базе знаний, должен закрыть этап при первом же
 * взгляде на карту, а не ждать утра.
 */
final class JourneyController extends Controller
{
    public function __construct(
        private readonly JourneyPresenter $presenter,
        private readonly RefreshJourneyProgress $refresh,
    ) {}

    /**
     * Значок на рельсе: есть ли человеку что проходить и ждут ли его поручения.
     *
     * «Есть маршрут» значит «в маршруте есть этапы»: пустая карта заведена у
     * каждого, и показывать раздел всем из-за неё незачем.
     */
    public function badge(Request $request): JsonResponse
    {
        /** @var User $person */
        $person = $request->user();

        $pendingMeetings = OnboardingJourneyMeeting::query()
            ->where('with_user_id', $person->getKey())
            ->whereNull('confirmed_at')
            ->whereHas('journeyStage.journey', fn ($journey) => $journey->alive())
            ->count();

        return response()->json(['data' => [
            'has_journey' => OnboardingJourney::query()
                ->where('user_id', $person->getKey())
                ->whereHas('stages')
                ->exists(),
            'pending_meetings' => $pendingMeetings + app(AssignedStages::class)->tasks($person)->count(),
            'has_assignments' => app(AssignedStages::class)->query($person)->exists(),
        ]]);
    }

    /**
     * Мой маршрут: карта, прогресс, ближайшая цель.
     */
    public function my(Request $request): JsonResponse
    {
        $journey = $this->journeyOf($request);

        $this->refresh->handle($journey);

        return response()->json(['data' => $this->presenter->present($journey->refresh())]);
    }

    /**
     * Сотрудник отмечает задание сделанным.
     */
    public function submitTask(Request $request, OnboardingJourneyTask $task, SubmitTask $submit): JsonResponse
    {
        $journey = $this->journeyOf($request);

        // Чужое задание — не найдено, как и чужая попытка.
        if ((int) $task->loadMissing('journeyStage')->journeyStage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        $submit->handle($task, $journey->user);

        return response()->json(['data' => $this->presenter->present($journey->refresh())]);
    }

    /**
     * Вопросы теста этапа — только когда этап открыт: карта не картинка, и
     * прочитать тест запертого дня заранее нельзя. Ключ прячет QuizResource.
     */
    public function quiz(Request $request, OnboardingJourneyStage $stage, StageRequirements $requirements): QuizResource
    {
        $journey = $this->journeyOf($request);

        if ((int) $stage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        $quiz = $stage->loadMissing('quiz.questions.options', 'quiz.examiner')->quiz;

        if ($quiz === null || $quiz->questions->isEmpty()) {
            abort(404);
        }

        $state = $requirements->of($journey)[(int) $stage->getKey()] ?? null;

        if ($state === null || $state->status === StageStatus::Locked) {
            abort(403, 'Этап ещё закрыт — тест ждёт своего дня.');
        }

        return QuizResource::make($quiz);
    }

    /**
     * Сдать тест этапа. Ответ — попытка с разбором, как у теста урока.
     */
    public function submitQuiz(
        SubmitQuizRequest $request,
        OnboardingJourneyStage $stage,
        SubmitStageQuiz $submit,
        QuizReview $review,
    ): QuizAttemptResource {
        $journey = $this->journeyOf($request);

        if ((int) $stage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        $attempt = $submit->handle($stage, $request->answers());

        $attempt->setAttribute('review', $review->of($attempt, $journey->user));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Разбор своей попытки — история теста этапа.
     */
    public function attempt(Request $request, QuizAttempt $attempt, QuizReview $review): QuizAttemptResource
    {
        /** @var User $employee */
        $employee = $request->user();

        if ((int) $attempt->user_id !== (int) $employee->getKey()) {
            abort(404);
        }

        $attempt->setAttribute('review', $review->of($attempt, $employee));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Маршрут вошедшего — он у человека один и заводится при первом взгляде:
     * пустая карта не ошибка, а начало.
     */
    private function journeyOf(Request $request): OnboardingJourney
    {
        /** @var User $employee */
        $employee = $request->user();

        return app(OpenJourney::class)->handle($employee);
    }
}
