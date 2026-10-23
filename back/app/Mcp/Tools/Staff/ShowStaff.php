<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Enums\Permission;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Карточка одного сотрудника — то же, что экран его профиля.
 *
 * Тем же правом, что и список: открыть карточку — то же чтение, только об одном
 * человеке.
 */
#[Name('staff_show')]
#[Title('Карточка сотрудника')]
#[Description('Всё об одном сотруднике: имя, связь, должность, отделы, дата приёма и стаж, положение (работает или уволен и почему), уровень доступа с отмеченными правами, наставник и ручные теги. Номер сотрудника берётся из staff_search.')]
final class ShowStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewUsers);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewUsers);

        $input = $request->validate(['employee_id' => ['required', 'integer']]);

        $person = $this->person((int) $input['employee_id']);

        return Response::structured(StaffCard::full(
            $person->load('roles', 'permissions', 'departments', 'mentor', 'staffTags', 'dismissedBy'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()
                ->required()
                ->description('Номер сотрудника из staff_search.'),
        ];
    }
}
