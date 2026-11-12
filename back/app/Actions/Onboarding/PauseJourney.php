<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourney;
use Illuminate\Support\Facades\Date;

/**
 * Пауза стажировки: уведомления замолкают, расписание замирает.
 *
 * Пауза — не прекращение: болезнь или отпуск наставника не делают стажёра
 * выбывшим. Сколько простояли — запомнит paused_at, и возобновление сдвинет
 * нераскрытые этапы ровно на это время (см. ResumeJourney).
 */
final readonly class PauseJourney
{
    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourney $journey): OnboardingJourney
    {
        if ($journey->status !== JourneyStatus::Active) {
            throw new ConflictException('Приостановить можно только идущий маршрут.');
        }

        $journey->fill([
            'status' => JourneyStatus::Paused,
            'paused_at' => Date::now(),
        ])->save();

        return $journey;
    }
}
