<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кому уходит рассылка.
 *
 * Три случая, и третий существует потому, что структура компании уже есть:
 * «складу» отправить проще, чем отмечать двадцать человек по одному.
 */
enum BroadcastAudience: string
{
    case Everyone = 'everyone';
    case Selected = 'selected';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Всем сотрудникам',
            self::Selected => 'Выбранным',
            self::Department => 'Отделу',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
