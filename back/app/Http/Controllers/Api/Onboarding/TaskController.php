<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Actions\Onboarding\ConfirmTask;
use App\Http\Controllers\Controller;
use App\Models\OnboardingJourneyTask;
use App\Models\User;
use App\Support\Onboarding\AssignedStages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Подтверждение задания ответственным.
 *
 * Права на маршруте нет намеренно: задание подтверждает наставник стажёра —
 * его назначает карточка сотрудника, и это доступ по назначению; проверяет
 * его само действие (ConfirmTask). Ведущему адаптацию тот же шаг доступен и
 * отсюда, и из журнала.
 */
final class TaskController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $tasks = app(AssignedStages::class)->tasks($request->user())
            ->with('journeyStage.journey.user')->get()
            ->map(fn ($task) => [
                'id' => $task->getKey(), 'text' => $task->text, 'submitted_at' => $task->submitted_at,
                'employee' => ['id' => $task->journeyStage->journey->user_id, 'name' => $task->journeyStage->journey->user?->name],
            ]);

        return response()->json(['data' => $tasks]);
    }

    public function confirm(Request $request, OnboardingJourneyTask $task, ConfirmTask $confirm): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $confirmed = $confirm->handle($task, $actor);

        return response()->json(['data' => [
            'id' => (int) $confirmed->getKey(),
            'confirmed_at' => $confirmed->confirmed_at,
        ]]);
    }
}
