<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sales;

use App\Actions\Sales\AcceptSuggestedSellers;
use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Models\SalesPerson;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Завести, поправить или снять сопоставление продавца.
 *
 * Имя не проверяется против выгрузки намеренно — та же причина, что и у формы:
 * сервер аналитики может быть недоступен, а править связи это мешать не должно,
 * да и заводят их наперёд, на продавца, который выйдет в понедельник.
 *
 * Снятая целиком связь удаляется, а не остаётся пустой строкой: строка без
 * отдела и без человека ничего не утверждает, но попадалась бы в списках и
 * выглядела бы сопоставлением.
 *
 * Здесь же — «принять все подсказки»: правка сразу сотни строк, и потому
 * отдельным доводом, который надо назвать явно.
 */
#[Name('sales_map_seller')]
#[Title('Сопоставить продавца с отделом')]
#[Description('Связывает имя продавца из торговой витрины с отделом и сотрудником платформы — или снимает связь, если не передать ни отдел, ни сотрудника. Можно вместо этого принять все подсказки разом (accept_suggestions: true): это правка сразу всех несопоставленных, и подтвердить её у сотрудника стоит обязательно.')]
final class MapSeller extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageSalesPlans);
    }

    public function handle(Request $request, AcceptSuggestedSellers $accept): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageSalesPlans);

        $input = Validator::make($request->all(), [
            'accept_suggestions' => ['nullable', 'boolean'],
            'external_name' => ['required_without:accept_suggestions', 'nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'external_name.required_without' => 'Не указано имя продавца из выгрузки.',
            'department_id.exists' => 'Такого отдела в структуре нет.',
            'user_id.exists' => 'Такого сотрудника нет.',
        ])->validate();

        if ((bool) ($input['accept_suggestions'] ?? false)) {
            return Response::structured(['accepted' => $accept()]);
        }

        $name = trim((string) $input['external_name']);
        $department = $input['department_id'] ?? null;
        $user = $input['user_id'] ?? null;

        if ($department === null && $user === null) {
            $removed = SalesPerson::query()->where('external_name', $name)->delete();

            return Response::structured([
                'external_name' => $name,
                'unmapped' => $removed > 0,
            ]);
        }

        $person = SalesPerson::query()->updateOrCreate(
            ['external_name' => $name],
            ['department_id' => $department, 'user_id' => $user],
        );

        return Response::structured([
            'external_name' => $person->external_name,
            'department_id' => $person->department_id,
            'user_id' => $person->user_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'external_name' => $schema->string()
                ->description('Имя продавца, как оно приходит из витрины, — из sales_sellers.'),
            'department_id' => $schema->integer()
                ->description('Номер отдела. Без него и без сотрудника связь снимается.'),
            'user_id' => $schema->integer()
                ->description('Номер сотрудника платформы — для личных планов и табло гонки.'),
            'accept_suggestions' => $schema->boolean()
                ->description('Принять подсказки по всем несопоставленным продавцам разом. Правит сразу сотни строк.'),
        ];
    }
}
