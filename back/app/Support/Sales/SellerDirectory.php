<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\User;
use App\Support\Analytics\AnalyticsUnavailable;
use App\Support\Analytics\EmployeeReport;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Illuminate\Support\Collection;

/**
 * Всё, что нужно, чтобы развести продавцов витрины по отделам, — одним ответом.
 *
 * Экран сопоставления отвечает на один вопрос — «чья это выручка» — и для него
 * нужны разом три вещи: имена из витрины с их оборотом, нынешние связи и то, из
 * чего выбирать. Разложенные по трём адресам, они приехали бы тремя запросами
 * на одно открытие, причём второй и третий — ради выпадающих списков.
 *
 * Оборот здесь не украшение, а порядок работы: имён восемьдесят пять, и
 * начинать надо с тех, за кем стоят миллионы, а не с тех, кто первым по
 * алфавиту. Поэтому список отсортирован по выручке, а сверху видно, сколько её
 * ещё не распределено.
 *
 * Срок — год. Не выбранный период: сопоставление делают однажды и надолго, и
 * продавец, который в этом месяце был в отпуске, не должен из списка исчезать.
 */
final readonly class SellerDirectory
{
    /** За какой срок берутся имена: тот же год, что у списков фильтров аналитики. */
    private const LOOKBACK_DAYS = 365;

    public function __construct(private EmployeeReport $report) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $departments = $this->departments();
        $people = $this->people();

        $mapped = SalesPerson::query()->get()->keyBy('external_name');
        $matcher = new NameMatcher($people, $departments);

        $sellers = [];
        $total = 0.0;
        $unassigned = 0.0;

        foreach ($this->fact() as $row) {
            $name = (string) $row['name'];
            $revenue = (float) $row['revenue'];
            $total += $revenue;

            /** @var SalesPerson|null $link */
            $link = $mapped->get($name);

            if ($link?->department_id === null) {
                $unassigned += $revenue;
            }

            $sellers[] = [
                'external_name' => $name,
                'revenue' => $revenue,
                'orders' => (int) $row['orders'],
                'department_id' => $link?->department_id,
                'user_id' => $link?->user_id,
                // Подсказка приезжает всегда, даже у сопоставленных: по ней
                // видно, что человека перевели в другой отдел.
                'suggestion' => $matcher->suggest($name),
            ];

            $mapped->forget($name);
        }

        // Сопоставления, под чьим именем за год не прошло ни одной продажи,
        // остаются в списке: ушедший продавец не повод молча потерять связь,
        // по которой считается прошлогодний отчёт.
        foreach ($mapped as $link) {
            $sellers[] = [
                'external_name' => $link->external_name,
                'revenue' => 0.0,
                'orders' => 0,
                'department_id' => $link->department_id,
                'user_id' => $link->user_id,
                'suggestion' => ['user_id' => null, 'department_id' => null],
            ];
        }

        return [
            'sellers' => $sellers,
            'departments' => $this->departmentOptions($departments),
            'people' => $people->map(static fn (User $person): array => [
                'id' => $person->id,
                'name' => trim($person->last_name.' '.$person->first_name),
                'job_title' => $person->job_title,
            ])->values()->all(),
            'totals' => [
                'revenue' => round($total, 2),
                'unassigned_revenue' => round($unassigned, 2),
                'unassigned_share' => $total > 0 ? round($unassigned / $total * 100, 1) : 0.0,
                'sellers' => count($sellers),
            ],
            'lookback_days' => self::LOOKBACK_DAYS,
        ];
    }

    /**
     * Имена и оборот из витрины.
     *
     * Недоступный сервер аналитики не должен закрывать экран: связи и отделы
     * лежат в своей базе, и править их можно и без выгрузки — просто без
     * подсказки о том, кто сколько продал.
     *
     * @return list<array<string, float|int|string>>
     */
    private function fact(): array
    {
        try {
            return $this->report->everyone(new ReportFilters(Period::preset('year')));
        } catch (AnalyticsUnavailable) {
            return [];
        }
    }

    /**
     * @return Collection<int, Department>
     */
    private function departments(): Collection
    {
        return Department::query()->orderBy('parent_id')->orderBy('position')->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function people(): Collection
    {
        return User::query()
            ->whereNull('dismissed_at')
            ->with('departments')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Отделы для выпадающего списка — с путём до корня.
     *
     * Путь нужен буквально: «Касса» есть и в гипермаркете, и в корпоративном
     * отделе, и выбрать из двух одинаковых строк нельзя.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<array{id: int, name: string, path: string}>
     */
    private function departmentOptions(Collection $departments): array
    {
        $byId = $departments->keyBy('id');

        return $departments
            // Корень в списке не нужен: «вся компания» — не отдел продаж, и
            // отнесённая на него выручка не сказала бы ничего.
            ->filter(static fn (Department $department): bool => $department->parent_id !== null)
            ->map(static function (Department $department) use ($byId): array {
                $path = [];
                $node = $department;

                while ($node !== null) {
                    $path[] = $node->name;
                    $node = $node->parent_id !== null ? $byId->get($node->parent_id) : null;
                }

                // Корень из пути убираем: он у всех один и места в строке не
                // стоит.
                array_pop($path);

                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'path' => implode(' / ', array_reverse($path)),
                ];
            })
            ->values()
            ->all();
    }
}
