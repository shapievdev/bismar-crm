<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\StaffReportExport;
use App\Models\User;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffScope;
use App\Support\Staff\StaffWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Выгрузка отчёта по штату файлом — и след, который она оставляет.
 *
 * Выгружает ровно то, что человек видит на экране: тот же срез, те же права,
 * та же область видимости. Директор направления выгрузит своё направление, а не
 * компанию, — и не потому, что так нарисована кнопка, а потому, что срез
 * процеживается тем же StaffQuery, что и сам отчёт.
 *
 * Каждая выгрузка пишется в журнал. Это требование 152-ФЗ, переведённое в
 * строку таблицы: списки с фамилиями покидают систему, и должно остаться, кто и
 * когда их вынес. Пишется до отдачи файла намеренно — запись, сделанная после,
 * не появилась бы, оборвись соединение на середине.
 */
final class StaffExportController extends Controller
{
    public function __construct(
        private readonly StaffWorkbook $workbook,
        private readonly StaffScope $scope,
        private readonly StaffQuery $query,
    ) {}

    public function __invoke(Request $request): BinaryFileResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);

        $input = $request->validate([
            'format' => ['nullable', 'string', 'in:xlsx,csv'],
            'names' => ['nullable', 'boolean'],
            'slice' => ['nullable', 'string', 'in:headcount,hired,left,early-left,without-hire-date'],
        ]);

        $format = ExportFormat::tryFrom((string) ($input['format'] ?? 'xlsx')) ?? ExportFormat::Xlsx;
        $withNames = $request->boolean('names', true);
        $slice = (string) ($input['slice'] ?? 'headcount');

        $filters = $this->query->from($request, $viewer);

        // Во временный файл, а не в поток ответа: книга XLSX — это zip, и
        // собрать её на лету, уже отдав заголовки, нельзя. Файл живёт ровно до
        // конца отдачи.
        $path = tempnam(sys_get_temp_dir(), 'shtat-');
        abort_if($path === false, 500, 'Не удалось подготовить файл выгрузки.');

        $rows = $this->workbook->write($path, $filters, $format, $withNames, $slice);

        StaffReportExport::query()->create([
            'user_id' => $viewer->getKey(),
            'format' => $format->value,
            'filters' => [...$filters->toArray(), 'slice' => $withNames ? $slice : null],
            'rows' => $rows,
            'with_names' => $withNames,
        ]);

        return response()
            ->download($path, $this->workbook->filename($filters, $format, $withNames), [
                'Content-Type' => $format->mime(),
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Журнал выгрузок.
     *
     * Открыт тому, кто видит компанию целиком: спрашивать «кто выносил списки»
     * — работа кадровой службы и собственника, а не директора направления,
     * который сам в этом журнале и значится.
     */
    public function journal(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->seesEverything($viewer), 403);

        $entries = StaffReportExport::query()
            ->with('user:id,first_name,last_name,middle_name')
            ->latest()
            ->limit(200)
            ->get();

        return response()->json(['data' => $entries->map(static fn (StaffReportExport $entry): array => [
            'id' => $entry->getKey(),
            'at' => $entry->created_at?->toIso8601String(),
            'who' => $entry->user?->name,
            'format' => $entry->format,
            'rows' => $entry->rows,
            'with_names' => $entry->with_names,
            'period' => [
                'from' => $entry->filters['from'] ?? null,
                'to' => $entry->filters['to'] ?? null,
            ],
        ])->all()]);
    }
}
