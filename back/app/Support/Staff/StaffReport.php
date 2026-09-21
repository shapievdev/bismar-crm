<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\DismissalReason;
use App\Enums\TenureTag;
use App\Models\Quiz;
use App\Models\User;
use App\Support\Search\Substring;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Движение персонала: кого приняли, кто ушёл, сколько продержались.
 *
 * Отчёт о людях, а не об обучении, и потому живёт отдельно от LearningReport:
 * там спрашивают «как проходят курсы», здесь — «что происходит со штатом».
 * Общего у них только то, что оба читают свою же базу.
 *
 * **Всё считается по датам, а не по положению «на сегодня».** Численность на 31
 * августа — это те, кто к тому дню был принят и ещё не уволен, а не те, кто
 * числится сейчас: иначе прошлое менялось бы каждый раз, когда кто-то уходит.
 * Отсюда и главное ограничение: человек без даты приёма не попадает в отчёт
 * вовсе — приложение не знает, работал ли он в августе. Таких отчёт считает
 * отдельно и называет числом, чтобы кадровик видел, сколько карточек ещё не
 * заполнено.
 */
final readonly class StaffReport
{
    public function __construct(private StaffTags $tags) {}

    /**
     * Сводные числа — то, ради чего экран открывают.
     *
     * @return array<string, int|float|null>
     */
    public function summary(StaffFilters $filters): array
    {
        $start = $this->headcountOn($filters, $filters->from->toDateString());
        $end = $this->headcountOn($filters, $filters->to->toDateString());

        $hired = (int) $this->matching($filters)
            ->whereBetween('hired_at', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->count();

        $left = (int) $this->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to])
            ->count();

        $average = $this->averageHeadcount($filters);

        /*
         * Ранняя текучесть — по формуле кадровой службы: ушедшие в первые 90
         * дней делятся на принятых за тот же период.
         *
         * Числитель и знаменатель здесь про разных людей, и это не ошибка
         * расчёта, а его смысл: вопрос стоит «сколько найма пропало впустую за
         * период», а не «какая доля этой когорты дожила».
         */
        $earlyLeft = (int) $this->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to])
            ->whereNotNull('hired_at')
            ->whereRaw('dismissed_at::date - hired_at < 90')
            ->count();

        return [
            'headcount_start' => $start,
            'headcount_end' => $end,
            'hired' => $hired,
            'left' => $left,

            'average_headcount' => $average,
            'turnover' => $average > 0 ? round($left / $average * 100, 1) : 0.0,
            'early_turnover' => $hired > 0 ? round($earlyLeft / $hired * 100, 1) : 0.0,
            'early_left' => $earlyLeft,

            'average_tenure' => $this->averageTenureOfWorking($filters),
            'average_life' => $this->averageTenureOfLeavers($filters),

            'onboarding' => $this->onboarding($filters),

            /*
             * Сколько карточек ещё без даты приёма.
             *
             * Стоит рядом с остальными числами намеренно: это мера того,
             * насколько отчёту вообще можно верить. Молчать о ней значило бы
             * показывать «принято 3» там, где принято тридцать.
             */
            'without_hire_date' => (int) $this->matching($filters)->whereNull('hired_at')->count(),
        ];
    }

    /**
     * Принято и уволено по месяцам — то, что рисуется графиком.
     *
     * Месяцы берутся сплошным рядом, а не из самих данных: месяц, в котором не
     * было ни приёмов, ни увольнений, обязан остаться на графике провалом, а не
     * исчезнуть, сдвинув соседние.
     *
     * @return list<array{month: string, hired: int, left: int}>
     */
    public function movement(StaffFilters $filters): array
    {
        $hired = $this->countByMonth($this->matching($filters)
            ->whereBetween('hired_at', [$filters->from->toDateString(), $filters->to->toDateString()]), 'hired_at');

        $left = $this->countByMonth($this->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to]), 'dismissed_at');

        $months = [];
        $cursor = $filters->from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($filters->to)) {
            $key = $cursor->format('Y-m');

            $months[] = [
                'month' => $key,
                'hired' => $hired[$key] ?? 0,
                'left' => $left[$key] ?? 0,
            ];

            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Таблица по подразделениям.
     *
     * Человек может состоять в нескольких отделах, и тогда он посчитан в
     * каждом: отчёт отвечает на вопрос «что происходит в этом подразделении», а
     * не делит людей на доли.
     *
     * @return list<array<string, mixed>>
     */
    public function byDepartment(StaffFilters $filters): array
    {
        $rows = DB::select($this->departmentSql($filters), $this->departmentBindings($filters));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'headcount' => (int) $row->headcount,
            'hired' => (int) $row->hired,
            'left' => (int) $row->left,
            'turnover' => $row->headcount > 0
                ? round((int) $row->left / (int) $row->headcount * 100, 1)
                : 0.0,
        ], $rows);
    }

    /**
     * Доли причин ухода среди уволенных за период.
     *
     * Считаются от тех, у кого причина проставлена, а число непроставленных
     * идёт отдельной строкой: сложи их в «другое» — и получится, что каждый
     * пятый ушёл по загадочной причине, хотя её просто не записали.
     *
     * @return array{total: int, unknown: int, rows: list<array{reason: string, label: string, count: int, share: float}>}
     */
    public function reasons(StaffFilters $filters): array
    {
        $rows = $this->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to])
            ->selectRaw('dismissal_reason, count(*) as total')
            ->groupBy('dismissal_reason')
            ->pluck('total', 'dismissal_reason');

        $unknown = (int) ($rows[''] ?? $rows->get(null) ?? 0);
        $known = $rows->filter(static fn (mixed $count, mixed $reason): bool => $reason !== null && $reason !== '');
        $total = (int) $known->sum();

        $result = [];

        foreach (DismissalReason::cases() as $reason) {
            $count = (int) ($known[$reason->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $result[] = [
                'reason' => $reason->value,
                'label' => $reason->label(),
                'count' => $count,
                'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        usort($result, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return ['total' => $total, 'unknown' => $unknown, 'rows' => $result];
    }

    /**
     * Распределение работающих по стажу.
     *
     * По работающим на конец периода, а не по всем когда-либо: вопрос стоит
     * «какая у нас сейчас команда».
     *
     * @return list<array{tag: string, label: string, range: string, count: int}>
     */
    public function tenure(StaffFilters $filters): array
    {
        $people = $this->matching($filters)
            ->whereNotNull('hired_at')
            ->where('hired_at', '<=', $filters->to->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('dismissed_at')
                ->orWhere('dismissed_at', '>', $filters->to))
            ->get(['id', 'hired_at']);

        $onProbation = $this->tags->stillOnProbation($people->modelKeys());

        $counts = array_fill_keys(array_map(
            static fn (TenureTag $tag): string => $tag->value,
            TenureTag::ordered(),
        ), 0);

        foreach ($people as $person) {
            $days = (int) $person->hired_at->startOfDay()->diffInDays($filters->to->startOfDay());
            $tag = $this->tags->tenureTag($days, in_array((int) $person->getKey(), $onProbation, strict: true));

            if ($tag !== null) {
                $counts[$tag->value]++;
            }
        }

        return array_map(static fn (TenureTag $tag): array => [
            'tag' => $tag->value,
            'label' => $tag->label(),
            'range' => $tag->range(),
            'count' => $counts[$tag->value],
        ], TenureTag::ordered());
    }

    /**
     * Люди — то, во что проваливаются из любой цифры.
     *
     * @param  string  $slice  какую именно цифру раскрывают
     * @return list<array<string, mixed>>
     */
    public function people(StaffFilters $filters, string $slice = 'headcount'): array
    {
        $query = $this->matching($filters)->with(['departments', 'staffTags']);

        $query = match ($slice) {
            'hired' => $query->whereBetween('hired_at', [$filters->from->toDateString(), $filters->to->toDateString()]),
            'left' => $query->whereBetween('dismissed_at', [$filters->from, $filters->to]),
            'early-left' => $query
                ->whereBetween('dismissed_at', [$filters->from, $filters->to])
                ->whereNotNull('hired_at')
                ->whereRaw('dismissed_at::date - hired_at < 90'),
            'without-hire-date' => $query->whereNull('hired_at'),
            default => $query
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('hired_at')
                    ->orWhere('hired_at', '<=', $filters->to->toDateString()))
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('dismissed_at')
                    ->orWhere('dismissed_at', '>', $filters->to)),
        };

        $people = $query->orderByRaw('coalesce(last_name, first_name) collate "und-x-icu"')->get();
        $onProbation = $this->tags->stillOnProbation($people->modelKeys());

        return $people->map(function (User $person) use ($onProbation): array {
            $days = $person->hired_at === null
                ? null
                : (int) $person->hired_at->startOfDay()->diffInDays(($person->dismissed_at ?? now())->startOfDay());

            $tag = $this->tags->tenureTag($days, in_array((int) $person->getKey(), $onProbation, strict: true));

            return [
                'id' => $person->getKey(),
                'name' => $person->name,
                'job_title' => $person->job_title,
                'departments' => $person->departments->pluck('name')->all(),
                'hired_at' => $person->hired_at?->toDateString(),
                'dismissed_at' => $person->dismissed_at?->toDateString(),
                'status' => $person->employmentStatus()->value,
                'status_label' => $person->employmentStatus()->label(),
                'work_mode_label' => $person->work_mode?->label(),
                'tenure_months' => $person->tenureMonths(),
                'dismissal_reason_label' => $person->dismissal_reason?->label(),
                'tenure_tag' => $tag?->value,
                'tenure_tag_label' => $tag?->label(),
                'tags' => $person->staffTags->pluck('name')->all(),
            ];
        })->all();
    }

    /* ---------- Кухня ---------- */

    /**
     * Все, кто попадает в срез, — до всякого счёта.
     *
     * Уволенные здесь есть, и это главное отличие от прочих отчётов
     * приложения: движение персонала без ушедших не движение.
     *
     * @return Builder<User>
     */
    private function matching(StaffFilters $filters): Builder
    {
        return User::query()
            ->when($filters->departmentIds !== null, fn (Builder $query) => $query
                ->whereHas('departments', fn (Builder $departments) => $departments
                    ->whereIn('departments.id', $filters->departmentIds === [] ? [0] : $filters->departmentIds)))
            ->when($filters->jobTitle !== null, fn (Builder $query) => Substring::apply(
                $query, $filters->jobTitle, ['job_title'],
            ))
            ->when($filters->workMode !== null, fn (Builder $query) => $query
                ->where('work_mode', $filters->workMode->value))
            // Все теги разом, а не любой из них: отбирая «кадровый резерв» и
            // «испытательный продлён», спрашивают о тех, у кого и то и другое.
            ->when($filters->tagIds !== [], function (Builder $query) use ($filters): void {
                foreach ($filters->tagIds as $tagId) {
                    $query->whereHas('staffTags', fn (Builder $tags) => $tags->whereKey($tagId));
                }
            });
    }

    /**
     * Сколько человек числилось на конкретный день.
     *
     * Принят не позже — и либо ещё не уволен, либо уволен позже. Декрет и ВРИО
     * считаются: они в штате.
     */
    private function headcountOn(StaffFilters $filters, string $day): int
    {
        return (int) $this->matching($filters)
            ->whereNotNull('hired_at')
            ->where('hired_at', '<=', $day)
            ->where(fn (Builder $query) => $query
                ->whereNull('dismissed_at')
                ->orWhere('dismissed_at', '>', $day))
            ->count();
    }

    /**
     * Среднесписочная — знаменатель текучести.
     *
     * Среднее по дням периода, а не полусумма краёв: месяц, в котором взяли
     * десятерых первого числа и уволили их тридцатого, полусуммой выглядит
     * спокойным. Считает Postgres одним проходом по ряду дат — тянуть за этим
     * триста шестьдесят пять запросов незачем.
     */
    private function averageHeadcount(StaffFilters $filters): float
    {
        $ids = $this->matching($filters)->whereNotNull('hired_at')->pluck('id')->all();

        if ($ids === []) {
            return 0.0;
        }

        $row = DB::selectOne(<<<'SQL'
            select coalesce(avg(headcount), 0) as average
            from (
                select count(u.id) as headcount
                from generate_series(?::date, ?::date, interval '1 day') as day
                left join users u
                    on u.id = any(?)
                    and u.hired_at <= day
                    and (u.dismissed_at is null or u.dismissed_at::date > day)
                group by day
            ) daily
            SQL, [
            $filters->from->toDateString(),
            $filters->to->toDateString(),
            '{'.implode(',', $ids).'}',
        ]);

        return round((float) $row->average, 1);
    }

    /** Средний стаж работающих на конец периода, в месяцах. */
    private function averageTenureOfWorking(StaffFilters $filters): ?int
    {
        $row = $this->matching($filters)
            ->whereNotNull('hired_at')
            ->where('hired_at', '<=', $filters->to->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('dismissed_at')
                ->orWhere('dismissed_at', '>', $filters->to))
            /*
             * Календарные месяцы через `age()`, а не делением дней.
             *
             * Вычитание дат в Postgres даёт целые дни, и «средний стаж» из них
             * пришлось бы получать делением на условную длину месяца — числом,
             * которого в календаре нет. `age()` считает так же, как считает
             * человек: с марта по сентябрь — шесть месяцев, сколько бы дней в
             * них ни было.
             */
            ->selectRaw(
                'avg(extract(year from age(?::date, hired_at)) * 12'
                .' + extract(month from age(?::date, hired_at))) as months',
                [$filters->to->toDateString(), $filters->to->toDateString()],
            )
            ->first();

        return $row?->months === null ? null : (int) round((float) $row->months);
    }

    /** Сколько в среднем продержались те, кто ушёл за период. */
    private function averageTenureOfLeavers(StaffFilters $filters): ?int
    {
        $row = $this->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to])
            ->whereNotNull('hired_at')
            ->selectRaw(
                'avg(extract(year from age(dismissed_at::date, hired_at)) * 12'
                .' + extract(month from age(dismissed_at::date, hired_at))) as months',
            )
            ->first();

        return $row?->months === null ? null : (int) round((float) $row->months);
    }

    /**
     * Онбординг: доля новичков, сдавших аттестацию испытательного срока.
     *
     * Считается по тем, у кого срок уже вышел: спрашивать с принятого вчера,
     * почему он не сдал тридцатидневную аттестацию, бессмысленно — и он же
     * утянул бы долю вниз, ничего при этом не сообщив.
     */
    private function onboarding(StaffFilters $filters): ?float
    {
        $newcomers = $this->matching($filters)
            ->whereBetween('hired_at', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->whereRaw('hired_at <= ?', [now()->subDays(30)->toDateString()])
            ->pluck('id')
            ->map(intval(...))
            ->all();

        if ($newcomers === []) {
            return null;
        }

        /*
         * Ни одной аттестации испытательного срока не заведено — считать
         * нечего.
         *
         * Формально доля вышла бы стопроцентной: никто её не провалил, потому
         * что никто её не проходил. Показать такое значит соврать самым
         * убедительным образом — числом.
         */
        if (! Quiz::query()->where('is_probation', true)->exists()) {
            return null;
        }

        $stuck = $this->tags->stillOnProbation($newcomers);

        return round((count($newcomers) - count($stuck)) / count($newcomers) * 100, 1);
    }

    /**
     * @param  Builder<User>  $query
     * @return array<string, int>
     */
    private function countByMonth(Builder $query, string $column): array
    {
        return $query
            ->selectRaw("to_char({$column}, 'YYYY-MM') as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            // Не `intval(...)`: ключ тут месяц, и первым же аргументом он
            // уехал бы в `intval` основанием системы счисления.
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    private function departmentSql(StaffFilters $filters): string
    {
        $scope = $filters->departmentIds === null
            ? ''
            : 'and d.id = any(?)';

        return <<<SQL
            select
                d.id,
                d.name,
                count(distinct u.id) filter (
                    where u.hired_at is not null
                      and u.hired_at <= ?
                      and (u.dismissed_at is null or u.dismissed_at > ?)
                ) as headcount,
                count(distinct u.id) filter (where u.hired_at between ? and ?) as hired,
                count(distinct u.id) filter (where u.dismissed_at between ? and ?) as "left"
            from departments d
            join department_members dm on dm.department_id = d.id
            join users u on u.id = dm.user_id
            where true {$scope}
            group by d.id, d.name
            -- Строка из одних нулей не сообщает ничего: подразделение, где за
            -- период никого не принимали, никто не уходил и никто не числится,
            -- в таблице только занимает место.
            having count(distinct u.id) filter (
                    where u.hired_at is not null
                      and u.hired_at <= ?
                      and (u.dismissed_at is null or u.dismissed_at > ?)
                ) > 0
                or count(distinct u.id) filter (where u.hired_at between ? and ?) > 0
                or count(distinct u.id) filter (where u.dismissed_at between ? and ?) > 0
            order by count(distinct u.id) desc, d.name collate "und-x-icu"
            SQL;
    }

    /**
     * @return list<string>
     */
    private function departmentBindings(StaffFilters $filters): array
    {
        $period = [
            $filters->to->toDateString(),
            $filters->to->toDateTimeString(),
            $filters->from->toDateString(),
            $filters->to->toDateString(),
            $filters->from->toDateTimeString(),
            $filters->to->toDateTimeString(),
        ];

        // Тот же период считается дважды — в выборке и в отсечке пустых строк,
        // — и значения для него подставляются тоже дважды. Порядок здесь
        // обязан повторять порядок вопросительных знаков в запросе.
        $bindings = $period;

        if ($filters->departmentIds !== null) {
            $bindings[] = '{'.implode(',', $filters->departmentIds === [] ? [0] : $filters->departmentIds).'}';
        }

        return [...$bindings, ...$period];
    }
}
