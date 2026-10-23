<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Support\Staff\JobTitles;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffReport;
use App\Support\Staff\StaffScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Движение персонала: сколько приняли, сколько ушло, какой стаж.
 *
 * Открыт двоим по-разному: кадровику и собственнику — вся компания, директору
 * направления — его ветка. Область считает не право, а место человека в
 * структуре (StaffScope), и срез через неё же процеживается: без этого директор
 * посмотрел бы соседнее направление, просто подставив номер. Поэтому здесь не
 * спрашивается право `can:` — пускает и отмеряет одно место, как и в
 * StaffController.
 *
 * Людей поимённо этот инструмент не отдаёт. Это персональные данные, и на сайте
 * они за отдельным адресом, в который проваливаются из одной цифры; машине же
 * список фамилий нужен реже, чем кажется, а утечь он может сразу весь.
 */
#[Name('staff_report')]
#[Title('Аналитика штата')]
#[Description('Движение персонала за период: численность, приёмы и увольнения, текучесть, разбивка по подразделениям, доли причин ухода и распределение по стажу. Область ответа — подразделения, доступные этому сотруднику: кадровику вся компания, директору направления его ветка. Поимённых списков не отдаёт.')]
final class StaffReportTool extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allowsAny(Permission::ViewStaffReport, Permission::ViewWholeStaffReport);
    }

    public function handle(
        Request $request,
        StaffReport $report,
        StaffScope $scope,
        StaffQuery $query,
        JobTitles $titles,
    ): ResponseFactory|Response {
        $actor = $this->permitAny($request, Permission::ViewStaffReport, Permission::ViewWholeStaffReport);

        if (! $scope->allows($actor)) {
            throw new AuthorizationException('Аналитика штата вам не открыта.');
        }

        $input = Validator::make($request->all(), StaffQuery::rules(), [
            'from.date' => 'Начало периода — дата вида ГГГГ-ММ-ДД.',
            'to.date' => 'Конец периода — дата вида ГГГГ-ММ-ДД.',
        ])->validate();

        $filters = $query->fromInput($input, $actor);

        return Response::structured([
            'summary' => $report->summary($filters),
            'movement' => $report->movement($filters),
            'departments' => $report->byDepartment($filters),
            'reasons' => $report->reasons($filters),
            'tenure' => $report->tenure($filters),
            'slice' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
                'sees_everything' => $scope->seesEverything($actor),
                // Чем отбирать дальше — из того, что реально проставлено, и в
                // пределах видимых этому человеку подразделений.
                'job_titles' => $titles->all($scope->departments($actor)),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Начало периода, ГГГГ-ММ-ДД. По умолчанию — как на экране.'),
            'to' => $schema->string()->description('Конец периода, ГГГГ-ММ-ДД.'),
            'departments' => $schema->array()->items($schema->integer())
                ->description('Номера подразделений. Чужие молча отбрасываются — область отмеряет структура, а не этот довод.'),
            'job_title' => $schema->string()->description('Должность — точным совпадением с одной из присланных в slice.job_titles.'),
            'work_mode' => $schema->string()->description('Режим работы: shift или office.'),
            'tags' => $schema->array()->items($schema->integer())->description('Номера ручных тегов из staff_tags_list.'),
        ];
    }
}
