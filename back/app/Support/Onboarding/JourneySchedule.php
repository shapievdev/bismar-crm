<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Enums\DayMode;
use App\Models\OnboardingJourney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Календарь маршрута: какой датой наступает «день N».
 *
 * Два счёта. По календарю день N — это start + (N−1) суток. По рабочим дням
 * суббота и воскресенье не считаются: стажёр, начавший в пятницу, получает
 * день 2 в понедельник. Праздников первая версия не знает — этап, попавший на
 * праздник, переносят рукой (MoveStageOpening).
 *
 * Этап открывается в начале своего дня: таймер здесь не нужен, «с утра» — и
 * есть то, что имеет в виду программа «день 3 — обучение с менеджером».
 */
final readonly class JourneySchedule
{
    /**
     * Дата, в которую наступает день маршрута.
     *
     * День 1 — сам день старта; у рабочего счёта стартовый выходной
     * сдвигается на ближайший понедельник: нельзя начать стажировку в день,
     * которого её календарь не знает.
     */
    public function dateOfDay(CarbonInterface $start, int $day, DayMode $mode): CarbonImmutable
    {
        $date = CarbonImmutable::parse($start->toDateString());

        if ($mode === DayMode::Calendar) {
            return $date->addDays(max(0, $day - 1))->startOfDay();
        }

        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        $left = max(0, $day - 1);

        while ($left > 0) {
            $date = $date->addDay();

            if (! $date->isWeekend()) {
                $left--;
            }
        }

        return $date->startOfDay();
    }

    /**
     * Какой сегодня день маршрута: 1 в день старта, дальше по счёту календаря.
     *
     * Null — дней в этом маршруте не считают вовсе: этапы стоят порядком, а не
     * датами, и выдумывать им номер дня незачем.
     *
     * У рабочего счёта выходной отвечает номером последнего рабочего дня: в
     * субботу маршрут не идёт, но и не откатывается.
     */
    public function currentDay(OnboardingJourney $journey, CarbonInterface $today): ?int
    {
        if ($journey->started_at === null) {
            return null;
        }

        $start = CarbonImmutable::parse($journey->started_at->toDateString());
        $date = CarbonImmutable::parse($today->toDateString());

        if ($date->lte($start)) {
            return 1;
        }

        if ($journey->day_mode === DayMode::Calendar) {
            return (int) $start->diffInDays($date) + 1;
        }

        $day = 1;
        $cursor = $start;

        while ($cursor->isWeekend()) {
            $cursor = $cursor->addDay();
        }

        while ($cursor->lt($date)) {
            $cursor = $cursor->addDay();

            if (! $cursor->isWeekend()) {
                $day++;
            }
        }

        return $day;
    }
}
