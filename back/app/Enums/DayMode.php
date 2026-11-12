<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Как маршрут считает «день N».
 *
 * По календарю — день 8 наступает через неделю, какой бы она ни была. По
 * рабочим дням — суббота и воскресенье не считаются: стажёр, начавший в
 * пятницу, получает день 2 в понедельник. Праздники первая версия не знает —
 * этап, попавший на праздник, переносят рукой.
 */
enum DayMode: string
{
    case Calendar = 'calendar';
    case Working = 'working';

    public function label(): string
    {
        return match ($this) {
            self::Calendar => 'Календарные дни',
            self::Working => 'Рабочие дни (без сб и вс)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
