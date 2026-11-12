<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingJourney;
use Illuminate\Support\Facades\DB;

/**
 * Порядок этапов маршрута: позиция в присланном списке и есть позиция на
 * карте. Порядок важен не только для вида — при строгом прохождении он и
 * решает, что за чем открывается.
 */
final readonly class ReorderJourneyStages
{
    /**
     * @param  list<int>  $stageIds
     */
    public function handle(OnboardingJourney $journey, array $stageIds): void
    {
        DB::transaction(function () use ($journey, $stageIds): void {
            foreach (array_values($stageIds) as $index => $stageId) {
                $journey->stages()->whereKey($stageId)->update(['position' => $index + 1]);
            }
        });
    }
}
