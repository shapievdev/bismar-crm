<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sales;

use App\Actions\Sales\SuggestPlansFromHistory;
use App\Enums\Permission;
use App\Enums\SalesPlanKind;
use App\Mcp\Tools\BismarTool;
use App\Models\SalesPlan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Поставить план, переписать поставленный или снять.
 *
 * Переписать, а не завести второй: план на месяц у цели один, и два означали
 * бы, что непонятно, по какому спрашивать. Ноль при этом законен — «план не
 * ставим» тоже решение, и отличается от «плана ещё нет»; снимают план
 * удалением, а не нулём.
 *
 * Сюда же «расставить по факту закрытых месяцев»: это решение о мерке, по
 * которой потом спросят, и приниматься оно должно человеком — отдельным
 * доводом, названным явно.
 */
#[Name('sales_set_plan')]
#[Title('Поставить план продаж')]
#[Description('Ставит или переписывает план продаж на месяц. Вид выбирает цель: department и member ставятся на отдел, person — на продавца из sales_sellers. Ноль разрешён — это требование «не продавать», а не отсутствие плана; чтобы снять план, передайте remove: true. Можно вместо этого расставить планы по факту закрытых месяцев (suggest: true).')]
final class SetSalesPlan extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageSalesPlans);
    }

    public function handle(Request $request, SuggestPlansFromHistory $suggest): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageSalesPlans);

        $input = Validator::make($request->all(), [
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'suggest' => ['nullable', 'boolean'],
            'remove' => ['nullable', 'boolean'],

            'kind' => ['required_without:suggest', 'nullable', 'string', 'in:'.implode(',', SalesPlanKind::values())],

            // Что из двух обязательно, решает вид: у плана отдела и норматива
            // цель — отдел, у личного — продавец.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sales_person_id' => ['nullable', 'integer', 'exists:sales_people,id'],

            'revenue' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
        ], [
            'month.regex' => 'Месяц передаётся как 2026-10.',
            'kind.in' => 'Неизвестный вид плана: бывает department, member или person.',
            'kind.required_without' => 'Не указан вид плана.',
        ])->validate();

        $month = CarbonImmutable::createFromFormat('Y-m-d', $input['month'].'-01')->startOfMonth();

        if ((bool) ($input['suggest'] ?? false)) {
            return Response::structured([
                'month' => $month->format('Y-m'),
                'suggested' => $suggest($month),
            ]);
        }

        $kind = SalesPlanKind::from((string) $input['kind']);
        $forPerson = $kind === SalesPlanKind::Person;

        $department = $forPerson ? null : ($input['department_id'] ?? null);
        $person = $forPerson ? ($input['sales_person_id'] ?? null) : null;

        if ($forPerson && $person === null) {
            return Response::error('Личный план ставится на продавца — укажите sales_person_id из sales_sellers.');
        }

        if (! $forPerson && $department === null) {
            return Response::error('План отдела и норматив ставятся на отдел — укажите department_id.');
        }

        $target = [
            'period' => $month->toDateString(),
            'kind' => $kind,
            // Для личного плана отдел не хранится: он у продавца, и записанный
            // вторым местом разошёлся бы с ним при переводе.
            'department_id' => $department,
            'sales_person_id' => $person,
        ];

        if ((bool) ($input['remove'] ?? false)) {
            return Response::structured([
                'removed' => SalesPlan::query()->where($target)->delete() > 0,
                'month' => $month->format('Y-m'),
                'kind' => $kind->value,
            ]);
        }

        if (($input['revenue'] ?? null) === null) {
            return Response::error('Не указана сумма плана. Ноль тоже можно — это требование, а не его отсутствие.');
        }

        $plan = SalesPlan::query()->updateOrCreate($target, ['revenue' => (float) $input['revenue']]);

        return Response::structured([
            'id' => $plan->id,
            'month' => $month->format('Y-m'),
            'kind' => $plan->kind->value,
            'department_id' => $plan->department_id,
            'sales_person_id' => $plan->sales_person_id,
            'revenue' => (float) $plan->revenue,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->required()->description('Месяц как 2026-10.'),
            'kind' => $schema->string()
                ->enum(SalesPlanKind::class)
                ->description('Вид плана: department — на отдел целиком, member — норматив на любого продавца отдела, person — личный план продавца.'),
            'department_id' => $schema->integer()->description('Номер отдела — для department и member.'),
            'sales_person_id' => $schema->integer()->description('Номер продавца из sales_sellers — для person.'),
            'revenue' => $schema->number()->description('Сумма плана в рублях. Ноль разрешён.'),
            'remove' => $schema->boolean()->description('Снять поставленный план вместо того, чтобы его менять.'),
            'suggest' => $schema->boolean()->description('Расставить планы на этот месяц по факту закрытых месяцев. Правит сразу все цели.'),
        ];
    }
}
