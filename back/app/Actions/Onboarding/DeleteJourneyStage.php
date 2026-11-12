<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Exceptions\ConflictException;
use App\Models\OnboardingJourneyStage;

/**
 * Убирает этап из личного маршрута — насовсем, вместе с его содержимым.
 *
 * Пройденный не трогаем: он уже история человека, и вычёркивать сделанное
 * задним числом нельзя. Передумали — переносите или правьте следующие.
 */
final readonly class DeleteJourneyStage
{
    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyStage $stage): void
    {
        if ($stage->isCompleted()) {
            throw new ConflictException('Этап уже пройден — убрать его нельзя, это история сотрудника.');
        }

        $stage->loadMissing('quiz')->quiz?->delete();
        $stage->delete();
    }
}
