<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\Permission;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourneyMeeting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * «Мы встретились» говорит тот, кто проводил.
 *
 * Подтверждает назначенный на встречу сотрудник — его доступ даёт само
 * назначение, без права, — либо ведущий адаптацию. Стажёр подтвердить не
 * может: встреча — требование этапа, и зачёт самому себе обесценил бы его.
 */
final readonly class ConfirmMeeting
{
    public function __construct(private RefreshJourneyProgress $refresh) {}

    /**
     * @throws ConflictException
     * @throws AuthorizationException
     */
    public function handle(OnboardingJourneyMeeting $meeting, User $actor): OnboardingJourneyMeeting
    {
        return DB::transaction(function () use ($meeting, $actor): OnboardingJourneyMeeting {
            $meeting = OnboardingJourneyMeeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $mayConfirm = (in_array((int) $actor->getKey(), $meeting->participantIds(), true)
                && $actor->getKey() !== $meeting->journeyStage->journey->user_id)
                || $actor->can(Permission::ManageOnboarding->value);

            if (! $mayConfirm) {
                throw new AuthorizationException('Встречу подтверждает тот, кто её проводил.');
            }

            if ($meeting->isConfirmed()) {
                throw new ConflictException('Встреча уже подтверждена.');
            }

            $meeting->fill([
                'confirmed_at' => Date::now(),
                'confirmed_by' => $actor->getKey(),
            ])->save();

            $this->refresh->handle($meeting->loadMissing('journeyStage.journey')->journeyStage->journey);

            return $meeting;
        });
    }
}
