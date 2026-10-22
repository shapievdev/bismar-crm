<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * На что поставлен план продаж.
 *
 * Три вида, и они не сводятся друг к другу. План отдела — не сумма планов его
 * продавцов: руководитель вправе требовать с отдела больше, чем раздал людям,
 * и обычно так и делает. Норматив — не план каждого поимённо: он подставляется
 * тому, кому личный план не ставили, в том числе новичку, которого завели
 * вчера.
 */
enum SalesPlanKind: string
{
    /** На отдел целиком. */
    case Department = 'department';

    /** На любого продавца отдела — норматив, если личного плана нет. */
    case Member = 'member';

    /** На конкретного продавца. */
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Отдел',
            self::Member => 'Любой продавец отдела',
            self::Person => 'Продавец',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
