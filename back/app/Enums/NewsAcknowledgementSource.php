<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsAcknowledgementSource: string
{
    /** Нажал «Ознакомлен». */
    case Confirmed = 'confirmed';

    /** Сдал приложенный к новости тест — этого достаточно. */
    case Quiz = 'quiz';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Подтвердил',
            self::Quiz => 'Сдал тест',
        };
    }
}
