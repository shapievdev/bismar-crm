<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Enums\DismissalReason;
use App\Enums\WorkMode;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\StaffTag;
use App\Models\User;
use App\Support\Staff\JobTitles;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffReport;
use App\Support\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Аналитика штата: движение персонала в разрезе структуры.
 *
 * Открыта двоим по-разному: кадровику и собственнику — вся компания, директору
 * направления — его ветка. Область считает не это право, а место человека в
 * структуре (StaffScope), и срез, пришедший от экрана, через неё же и
 * процеживается: без этого директор посмотрел бы соседнее направление, просто
 * подставив его номер в адрес.
 *
 * Одним ответом, а не шестью: экран показывает сводку, график, таблицу,
 * причины и распределение по стажу вместе. Отдельно спрашивается только список
 * людей — в него проваливаются из одной цифры.
 */
final class StaffController extends Controller
{
    public function __construct(
        private readonly StaffReport $report,
        private readonly StaffScope $scope,
        private readonly StaffQuery $query,
        private readonly JobTitles $titles,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);

        $filters = $this->query->from($request, $viewer);

        return response()->json(['data' => [
            'summary' => $this->report->summary($filters),
            'movement' => $this->report->movement($filters),
            'departments' => $this->report->byDepartment($filters),
            'reasons' => $this->report->reasons($filters),
            'tenure' => $this->report->tenure($filters),

            // Чем человеку разрешено фильтровать. Приходит вместе с отчётом:
            // справочники маленькие, а второй запрос ради выпадающего списка —
            // это второй круг ожидания на открытии страницы.
            'filters' => [
                'period' => ['from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString()],
                'departments' => $this->departmentOptions($viewer),
                'job_titles' => $this->jobTitles($viewer),
                'work_modes' => array_map(
                    static fn (WorkMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
                    WorkMode::cases(),
                ),
                'reasons' => array_map(
                    static fn (DismissalReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
                    DismissalReason::cases(),
                ),
                'tags' => StaffTag::query()->ordered()->get(['id', 'name'])->all(),
                'sees_everything' => $this->scope->seesEverything($viewer),
            ],
        ]]);
    }

    /**
     * Люди за цифрой.
     *
     * Своим адресом, а не внутри общего ответа: список — это персональные
     * данные, и присылать его всякому, кто открыл сводку, незачем. Какую
     * именно цифру раскрывают, говорит `slice`.
     */
    public function people(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);

        $slice = (string) $request->validate([
            'slice' => ['nullable', 'string', 'in:headcount,hired,left,early-left,without-hire-date'],
        ])['slice'] ?? 'headcount';

        return response()->json(['data' => [
            'slice' => $slice,
            'people' => $this->report->people($this->query->from($request, $viewer), $slice),
        ]]);
    }

    /**
     * Подразделения, которые человеку можно выбрать в фильтре.
     *
     * @return list<array{id: int, name: string}>
     */
    private function departmentOptions(User $viewer): array
    {
        $allowed = $this->scope->departments($viewer);

        return Department::query()
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed === [] ? [0] : $allowed))
            ->ordered()
            ->get(['id', 'name'])
            ->map(static fn (Department $one): array => ['id' => (int) $one->getKey(), 'name' => $one->name])
            ->all();
    }

    /**
     * Должности, которые человеку можно выбрать в фильтре, — суженные тем же
     * местом в структуре, что и весь отчёт.
     *
     * Сам список собирается в App\Support\Staff\JobTitles: его спрашивает ещё и
     * список сотрудников, а разошедшиеся запросы однажды предложили бы в двух
     * местах разные должности.
     *
     * @return list<string>
     */
    private function jobTitles(User $viewer): array
    {
        return $this->titles->all($this->scope->departments($viewer));
    }
}
