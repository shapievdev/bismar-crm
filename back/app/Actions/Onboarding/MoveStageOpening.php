<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Exceptions\ConflictException;
use App\Models\OnboardingJourneyStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Перенос и досрочное открытие — одно действие: правка даты открытия этапа.
 *
 * Досрочно открыть = поставить «сейчас». Пройденный этап не трогается: его
 * открытие — история, а не настройка.
 */
final readonly class MoveStageOpening
{
    /**
     * @param  string|null  $opensAt  null — открыть прямо сейчас.
     *
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyStage $stage, ?string $opensAt): OnboardingJourneyStage
    {
        if ($stage->isCompleted()) {
            throw new ConflictException('Этап уже пройден — переносить нечего.');
        }

        $stage->fill([
            'opening_override_at' => $opensAt === null ? Date::now() : null,
            'notified_at' => null,
            'opens_at' => $opensAt === null
                ? Date::now()
                : CarbonImmutable::parse($opensAt)->startOfDay(),
        ])->save();

        return $stage;
    }
}
