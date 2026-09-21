<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsAcknowledgementSource: string
{
    /** Нажал «Ознакомлен». */
    case Confirmed = 'confirmed';

    /** Сдал приложенный к новости тест — этого достаточно. */
    case Quiz = 'quiz';

    /**
     * Прошёл обязательный опрос при новости (2026-09-21).
     *
     * Отдельное основание, а не «подтвердил»: разбирающему журнал не всё равно,
     * прочитал человек новость и нажал кнопку — или высказался о ней.
     */
    case Survey = 'survey';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Подтвердил',
            self::Quiz => 'Сдал тест',
            self::Survey => 'Прошёл опрос',
        };
    }
}
