<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\Permission;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourneyTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;

/**
 * Ответственный подтверждает задание стажёра.
 *
 * Подтверждает наставник стажёра — его назначает карточка сотрудника, и это
 * доступ по назначению, без права, — либо ведущий адаптацию. Подтверждение не
 * требует заявки стажёра: наставник, видевший работу, вправе зачесть её сам.
 */
final readonly class ConfirmTask
{
    public function __construct(private RefreshJourneyProgress $refresh) {}

    /**
     * @throws ConflictException
     * @throws AuthorizationException
     */
    public function handle(OnboardingJourneyTask $task, User $actor): OnboardingJourneyTask
    {
        $journey = $task->loadMissing('journeyStage.journey.user')->journeyStage->journey;

        $mayConfirm = $actor->can(Permission::ManageOnboarding->value)
            || in_array((int) $actor->getKey(), array_map(intval(...), $task->journeyStage->responsible_ids ?? []), true)
            || ($journey->user?->mentor_id !== null && $actor->getKey() === $journey->user->mentor_id);

        if (! $mayConfirm) {
            throw new AuthorizationException('Задание подтверждает наставник или ведущий адаптацию.');
        }

        if ($task->isDone()) {
            throw new ConflictException('Задание уже зачтено.');
        }

        $task->fill([
            'submitted_at' => $task->submitted_at ?? Date::now(),
            'confirmed_at' => Date::now(),
            'confirmed_by' => $actor->getKey(),
        ])->save();

        $this->refresh->handle($journey);

        return $task;
    }
}
