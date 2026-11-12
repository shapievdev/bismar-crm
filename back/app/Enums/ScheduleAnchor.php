<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * От какой даты отсчитывать дни шаблона, когда его накладывают на маршрут.
 *
 * Дни в шаблоне относительные — «день 1», «день 5», — и во что они превратятся
 * на календаре, решают здесь, в момент наложения. Один и тот же трафарет так
 * служит и новичку (от даты приёма), и переводу на другую должность (от
 * сегодня), и заранее собранному вводу в новый магазин (от выбранной даты).
 *
 * `None` — особый и нужный случай: дни не считаются вовсе. Этапы встают по
 * порядку и открываются очередью, а даты проставят руками, когда станет
 * понятно. Пустое расписание честнее выдуманного.
 */
enum ScheduleAnchor: string
{
    /** От даты приёма сотрудника (users.hired_at). */
    case Hired = 'hired';

    /** От названной даты. */
    case Date = 'date';

    /** От сегодняшнего дня. */
    case Today = 'today';

    /** Без дат: только порядок этапов. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Hired => 'От даты приёма',
            self::Date => 'От выбранной даты',
            self::Today => 'С сегодняшнего дня',
            self::None => 'Без дат — расставлю сам',
        };
    }

    /** Нужна ли этому отсчёту названная дата. */
    public function needsDate(): bool
    {
        return $this === self::Date;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $anchor): string => $anchor->value, self::cases());
    }
}
