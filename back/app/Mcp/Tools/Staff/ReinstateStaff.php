<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\ChangeEmployment;
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
 * Возвращение в строй.
 *
 * Уровень доступа и отмеченные права возвращаются те же: увольнение их не
 * снимало, и вернувшийся продолжает с того места, где остановился.
 */
#[Name('staff_reinstate')]
#[Title('Вернуть сотрудника в строй')]
#[Description('Отменяет увольнение: сотрудник снова может входить, с прежним уровнем доступа и прежними правами. Причина ухода при этом стирается — вернувшийся числится работающим.')]
final class ReinstateStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, ChangeEmployment $employment): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageUsers);

        $person = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'reinstated' => StaffCard::full($employment
                ->reinstate($person, $actor)
                ->load('departments', 'mentor', 'staffTags')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер уволенного сотрудника.'),
        ];
    }
}
