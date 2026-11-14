<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingJourneyTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AssignedStages
{
    public function query(User $person): Builder
    {
        return OnboardingJourneyStage::query()->whereHas('journey.user')
            ->where(function (Builder $q) use ($person): void {
                $q->whereJsonContains('responsible_ids', (int) $person->id)
                    ->orWhereHas('tasks', fn ($tasks) => $tasks->where('confirm_by', 'responsible')->whereJsonContains('responsible_ids', (int) $person->id))
                    ->orWhereHas('meetings', fn ($m) => $m->where('with_user_id', $person->id))
                    ->orWhereHas('journey.user', fn ($u) => $u->where('mentor_id', $person->id));
            });
    }

    public function tasks(User $person): Builder
    {
        return OnboardingJourneyTask::query()->whereNull('confirmed_at')
            ->where('confirm_by', 'responsible')
            ->whereHas('journeyStage.journey', fn ($journey) => $journey->where('status', 'active')
                ->whereHas('user', fn ($user) => $user->whereNull('dismissed_at')))
            ->where(fn ($assigned) => $assigned
                ->whereJsonContains('responsible_ids', (int) $person->id)
                ->orWhere(fn ($fallback) => $fallback->whereJsonLength('responsible_ids', 0)
                    ->whereHas('journeyStage', fn ($stage) => $stage->whereJsonContains('responsible_ids', (int) $person->id)))
                ->orWhereHas('journeyStage.journey.user', fn ($user) => $user->where('mentor_id', $person->id)));
    }
}
