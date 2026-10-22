<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Должности — из того, что реально проставлено.
 *
 * Справочника должностей в приложении нет: должность здесь строка, которую
 * пишет кадровик. Список собирается из заполненного, чтобы фильтр предлагал то,
 * что найдётся, а не то, что кто-то однажды придумал.
 *
 * Спрашивают его двое — список сотрудников и отчёт о движении персонала, — и
 * запрос у них один и тот же. Отличие одно: отчёт сужен подразделениями,
 * которые этому человеку видно, а список сотрудников показывает всех.
 */
final class JobTitles
{
    /**
     * @param  list<int>|null  $departments  какими подразделениями сузить;
     *                                       null — не сужать вовсе
     * @return list<string>
     */
    public function all(?array $departments = null): array
    {
        /** @var list<string> $titles */
        $titles = User::query()
            ->whereNotNull('job_title')
            ->when($departments !== null, fn (Builder $query) => $query
                ->whereHas('departments', fn (Builder $inner) => $inner
                    ->whereIn('departments.id', $departments === [] ? [0] : $departments)))
            // Группировкой, а не `distinct`: при `select distinct` Postgres
            // требует, чтобы выражение сортировки стояло и в списке выборки, а
            // сортируем мы по коллации — «Бухгалтер» должен идти перед
            // «Водителем», а не после «Washer».
            ->groupBy('job_title')
            ->orderByRaw('job_title collate "und-x-icu"')
            ->pluck('job_title')
            ->all();

        return $titles;
    }
}
