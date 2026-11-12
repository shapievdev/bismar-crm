<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Models\OnboardingJourney;
use App\Support\Onboarding\ProfessionalKit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateJourneySettings
{
    public function handle(OnboardingJourney $journey, array $attributes): OnboardingJourney
    {
        return DB::transaction(function () use ($journey, $attributes) {
            $journey = OnboardingJourney::lockForUpdate()->findOrFail($journey->id);
            $old = ProfessionalKit::definitions($journey->kit_items);
            $kit = ProfessionalKit::validated($attributes['kit_items'] ?? $old,
                (bool) ($attributes['requires_graduation'] ?? $journey->requires_graduation));
            $next = collect($kit)->keyBy('id');
            $receipts = ProfessionalKit::receipts($journey);
            foreach ($old as $item) {
                if (! isset($receipts[$item['id']]['confirmed_at'])) {
                    continue;
                }
                $replacement = $next->get($item['id']);
                if ($replacement === null || $replacement['label'] !== $item['label'] || $replacement['kind'] !== $item['kind']) {
                    throw ValidationException::withMessages(['kit_items' => 'Пункт «'.$item['label'].'» уже выдан. Его нельзя удалить, переименовать или изменить назначение.']);
                }
            }
            $journey->fill([...$attributes, 'kit_items' => $kit]);
            if ($journey->isCompleted() && $journey->requires_graduation && ! $journey->isFullyEquipped()) {
                $journey->fill(['status' => JourneyStatus::Active, 'completed_at' => null]);
            }
            $journey->save();

            return $journey;
        });
    }
}
