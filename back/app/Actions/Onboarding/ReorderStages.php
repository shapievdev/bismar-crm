<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Порядок этапов — присланным списком номеров: позиция в массиве и есть
 * позиция на карте. Не названные номера не трогаются — их отдаёт сам редактор
 * следующим сохранением.
 */
final readonly class ReorderStages
{
    /**
     * @param  list<int>  $stageIds
     */
    public function handle(OnboardingTemplate $template, array $stageIds): void
    {
        DB::transaction(function () use ($template, $stageIds): void {
            foreach (array_values($stageIds) as $index => $stageId) {
                $template->stages()->whereKey($stageId)->update(['position' => $index + 1]);
            }
        });
    }
}
