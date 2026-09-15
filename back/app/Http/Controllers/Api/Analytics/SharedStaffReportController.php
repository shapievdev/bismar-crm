<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Models\StaffReportLink;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffReport;
use Illuminate\Http\JsonResponse;

/**
 * Отчёт по токену — единственное место приложения, куда пускают без входа.
 *
 * Отдаёт только цифры. Метода, который вернул бы людей, здесь нет и не должно
 * появиться: это не настройка и не право, а свойство самой ссылки — она уходит
 * наружу, в переписки и письма, и то, что по ней отдаётся, перестаёт быть под
 * контролем в ту же минуту.
 *
 * Отчёт считается заново на каждое открытие, а не хранится: замороженная выборка
 * месячной давности показывала бы прошлое как настоящее, а срез пересчитать
 * дёшево.
 *
 * Сводку он отдаёт урезанной. «Без даты приёма: семеро» — цифра для кадровика,
 * который пойдёт их заполнять; наружу она сообщает только то, что в учёте дыра.
 */
final class SharedStaffReportController extends Controller
{
    public function __construct(
        private readonly StaffReport $report,
        private readonly StaffQuery $query,
    ) {}

    public function __invoke(string $token): JsonResponse
    {
        $link = StaffReportLink::query()->where('token', $token)->first();

        // Несуществующий и отозванный токен отвечают по-разному намеренно:
        // «отозвана» подсказывает, что просить её снова не нужно, а
        // «не найдено» — что ссылку скопировали не целиком.
        abort_if($link === null, 404);

        $refusal = $link->refusal();

        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 410);
        }

        $filters = $this->query->fromStored($link->filters);
        $summary = $this->report->summary($filters);

        unset($summary['without_hire_date']);

        return response()->json(['data' => [
            'period' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
            ],
            'expires_at' => $link->expires_at->toIso8601String(),
            'summary' => $summary,
            'movement' => $this->report->movement($filters),
            'departments' => $this->report->byDepartment($filters),
            'reasons' => $this->report->reasons($filters),
            'tenure' => $this->report->tenure($filters),
        ]]);
    }
}
