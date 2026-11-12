<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingStage;

/**
 * Этап уходит мягко: новые назначения его не увидят, а идущие стажёры
 * дочитают содержимое через withTrashed — их строка этапа держит stage_id.
 */
final readonly class DeleteStage
{
    public function handle(OnboardingStage $stage): void
    {
        $stage->delete();
    }
}
