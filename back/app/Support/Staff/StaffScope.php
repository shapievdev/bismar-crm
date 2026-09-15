<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Structure\DepartmentReach;

/**
 * Кому какая часть штата видна.
 *
 * Здесь — вторая половина требования 152-ФЗ: право отвечает «пускать ли»,
 * структура — «куда». Разведены они намеренно. Прав два и они выдаются рукой, а
 * область меняется сама, когда человека переводят в другой отдел, — свяжи их в
 * одно, и всякий перевод требовал бы ещё и переписать права.
 *
 * Директор видит своё подразделение **вместе со всем, что под ним**: направление
 * — это ветка, а не одна строка справочника, и «свои» для него — все, кто в
 * ветке. Вверх видимость не идёт никогда: начальник отдела не смотрит движение
 * по компании.
 */
final readonly class StaffScope
{
    public function __construct(private DepartmentReach $reach) {}

    /**
     * Может ли этот человек вообще открыть отчёт.
     */
    public function allows(User $viewer): bool
    {
        return $viewer->can(Permission::ViewWholeStaffReport->value)
            || $viewer->can(Permission::ViewStaffReport->value);
    }

    /** Видит ли он компанию целиком. */
    public function seesEverything(User $viewer): bool
    {
        return $viewer->can(Permission::ViewWholeStaffReport->value);
    }

    /**
     * Подразделения, которые ему открыты.
     *
     * `null` — вся компания: это не то же самое, что пустой список. Пустой
     * список означает «не открыто ни одного», и отчёт по нему честно пуст, а
     * `null` снимает ограничение вовсе.
     *
     * @return list<int>|null
     */
    public function departments(User $viewer): ?array
    {
        if ($this->seesEverything($viewer)) {
            return null;
        }

        /** @var list<int> $own */
        $own = $viewer->departments()->pluck('departments.id')->map(intval(...))->all();

        return $this->reach->branch($own);
    }

    /**
     * Сужает запрошенные подразделения до тех, что человеку и так открыты.
     *
     * Фильтр приходит от экрана, а экран — от человека: без этого директор
     * посмотрел бы соседнее направление, просто подставив его номер в адрес.
     *
     * @param  list<int>  $wanted
     * @return list<int>|null
     */
    public function narrow(User $viewer, array $wanted): ?array
    {
        $allowed = $this->departments($viewer);

        if ($wanted === []) {
            return $allowed;
        }

        // Спрошенное раскрывается вниз по дереву: выбрав направление, человек
        // имеет в виду и всё, что под ним.
        $asked = $this->reach->branch($wanted);

        return $allowed === null ? $asked : array_values(array_intersect($asked, $allowed));
    }
}
