<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingJourney;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateJourneySettings
{
    public function handle(OnboardingJourney $journey, array $attributes): OnboardingJourney
    {
        return DB::transaction(function () use ($journey, $attributes) {
            $journey = OnboardingJourney::lockForUpdate()->findOrFail($journey->id);
            $journey->fill(Arr::only($attributes, ['settings', 'allow_ahead', 'meeting_reminder_minutes']));
            $journey->requires_graduation = false;
            $journey->save();

            return $journey;
        });
    }
}
