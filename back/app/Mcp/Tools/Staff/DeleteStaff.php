<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\DeleteUser;
use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Удаление учётной записи — крайняя мера, и только суперадминистратором.
 *
 * Обыкновенный уход из компании — это увольнение, после которого человек
 * остаётся подписан под тем, что написал. Удаляют, когда записи не должно быть
 * видно вовсе: заведённой по ошибке или той, что просят убрать.
 *
 * Удаление мягкое и отменяемое (staff_restore), но в приложении удалённого нет
 * нигде — включая отчёт о движении персонала. Уволить человека перед удалением
 * обязательно: на этом стоят все прочие отчёты, которые ушедших не считают.
 */
#[Name('staff_delete')]
#[Title('Удалить учётную запись')]
#[Description('Удаляет учётную запись сотрудника. Доступно только суперадминистратору и только для уже уволенного. Удаление мягкое: запись исчезает из приложения и из отчёта о штате, но её можно вернуть (staff_restore). Это крайняя мера — обычный уход из компании оформляется увольнением. Обязательно подтвердите у сотрудника, прежде чем удалять.')]
final class DeleteStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        // Право на маршруте то же, что у правки людей, но пускает не оно: и
        // удаляет, и возвращает один суперадминистратор.
        return $this->isSuperAdministrator() && $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, DeleteUser $deleteUser): ResponseFactory|Response
    {
        $actor = $this->permitSuperAdministrator($request);

        $person = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        return $this->attempt(function () use ($actor, $deleteUser, $person): ResponseFactory {
            $deleteUser->handle($person, $actor);

            return Response::structured([
                'deleted' => true,
                'id' => $person->getKey(),
                'name' => $person->name,
                'restorable' => true,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()
                ->required()
                ->description('Номер уволенного сотрудника, чью запись удаляют.'),
        ];
    }
}
