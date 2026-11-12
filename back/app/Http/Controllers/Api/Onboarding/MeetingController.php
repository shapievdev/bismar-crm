<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Actions\Onboarding\ConfirmMeeting;
use App\Http\Controllers\Controller;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Models\OnboardingJourneyMeeting;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\QuizReview;
use App\Support\Onboarding\AssignedStages;
use App\Support\Onboarding\JourneyPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Подтверждение встречи ответственным.
 *
 * Права на маршруте нет намеренно: встречу подтверждает тот, на кого она
 * назначена, — доступ даёт назначение, и проверяет его само действие. Поэтому
 * наставник без единого права в системе может сказать «мы встретились».
 */
final class MeetingController extends Controller
{
    public function stages(Request $request, AssignedStages $assigned, JourneyPresenter $presenter): JsonResponse
    {
        $stages = $assigned->query($request->user())->with('journey.user')->get();
        $rows = [];
        foreach ($stages->groupBy('journey_id') as $group) {
            $journey = $group->first()->journey;
            $payload = $presenter->present($journey);
            $ids = $group->modelKeys();
            foreach ($payload['stages'] as $stage) {
                if (in_array($stage['id'], $ids, true)) {
                    $rows[] = ['employee' => $payload['employee'], 'stage' => $stage];
                }
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function attempt(Request $request, QuizAttempt $attempt, AssignedStages $assigned, QuizReview $review): QuizAttemptResource
    {
        $quiz = $attempt->quiz;
        if ($quiz === null || $quiz->quizzable_type !== 'onboarding_journey_stage'
            || ! $assigned->query($request->user())->whereKey($quiz->quizzable_id)->whereHas('journey', fn ($q) => $q->where('user_id', $attempt->user_id))->exists()) {
            abort(404);
        }
        $attempt->setAttribute('review', $review->of($attempt, $attempt->user));

        return QuizAttemptResource::make($attempt);
    }

    public function confirm(Request $request, OnboardingJourneyMeeting $meeting, ConfirmMeeting $confirm): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $confirmed = $confirm->handle($meeting, $actor);

        return response()->json(['data' => [
            'id' => (int) $confirmed->getKey(),
            'confirmed_at' => $confirmed->confirmed_at,
        ]]);
    }

    /**
     * Мои встречи-поручения: что мне предстоит провести и подтвердить.
     * Для значка и списка у ответственного — наставника или менеджера.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $meetings = OnboardingJourneyMeeting::query()
            ->where('with_user_id', $actor->getKey())
            ->whereNull('confirmed_at')
            ->whereHas('journeyStage.journey', fn ($journey) => $journey->alive())
            ->with(['journeyStage.journey.user'])
            ->orderByRaw('scheduled_at nulls last')
            ->get()
            ->map(fn (OnboardingJourneyMeeting $meeting): array => [
                'id' => (int) $meeting->getKey(),
                'topic' => $meeting->topic,
                'format' => $meeting->format->value,
                'format_label' => $meeting->format->label(),
                'place' => $meeting->place,
                'scheduled_at' => $meeting->scheduled_at,
                'employee' => [
                    'id' => (int) $meeting->journeyStage->journey->user_id,
                    'name' => (string) $meeting->journeyStage->journey->user?->name,
                ],
                'journey_id' => (int) $meeting->journeyStage->journey_id,
            ]);

        return response()->json(['data' => $meetings]);
    }
}
