<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Как человек работает: по сменам или в офисе.
 *
 * Различать их нужно не ради красоты отчёта: у смен и у офиса разный ритм
 * выхода и разная текучесть — в зале люди меняются в разы чаще. Сложенные в
 * одну цифру, они дают среднее, которого нет ни у тех ни у других, и по нему
 * принимают решения не о том.
 */
enum WorkMode: string
{
    case Shift = 'shift';

    case Office = 'office';

    public function label(): string
    {
        return match ($this) {
            self::Shift => 'Сменный',
            self::Office => 'Офис',
        };
    }
}
