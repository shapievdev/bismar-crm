<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsAudience: string
{
    /** Всем, кто вошёл в систему. */
    case Everyone = 'everyone';

    /** Только тем, кого назвали поимённо, — см. таблицу news_recipients. */
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Всем сотрудникам',
            self::Selected => 'Выбранным сотрудникам',
        };
    }

    public function isSelected(): bool
    {
        return $this === self::Selected;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $audience): string => $audience->value, self::cases());
    }
}
