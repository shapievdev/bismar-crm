<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кем человек числится в отделе.
 *
 * Три роли и ровно в этом порядке: так их читают в карточке отдела и так же
 * разбивают на группы в списке людей.
 *
 * Это про место в структуре, а не про права: уровень доступа по-прежнему
 * решает AccessLevel, и руководитель отдела не получает от этой строки ничего,
 * кроме места в дереве (решение пользователя 2026-08-30).
 */
enum DepartmentRole: string
{
    case Head = 'head';
    case Deputy = 'deputy';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Head => 'Руководитель',
            self::Deputy => 'Заместитель',
            self::Member => 'Сотрудник',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
