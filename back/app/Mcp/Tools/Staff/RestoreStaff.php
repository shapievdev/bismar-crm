<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\RestoreUser;
use App\Enums\Permission;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Возвращение удалённой записи — уволенной, какой её и удаляли.
 *
 * Запись ищется среди удалённых намеренно: обычный поиск их не видит, и номер
 * сюда приходит из staff_search с `deleted: true`.
 */
#[Name('staff_restore')]
#[Title('Вернуть удалённую запись')]
#[Description('Возвращает удалённую учётную запись. Доступно только суперадминистратору. Запись возвращается уволенной — какой её и удаляли; чтобы человек снова работал, после этого нужен staff_reinstate. Номера удалённых записей даёт staff_search с deleted: true.')]
final class RestoreStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->isSuperAdministrator() && $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, RestoreUser $restoreUser): ResponseFactory|Response
    {
        $actor = $this->permitSuperAdministrator($request);

        $input = $request->validate(['employee_id' => ['required', 'integer']]);

        $deleted = User::onlyTrashed()->find((int) $input['employee_id']);

        if (! $deleted instanceof User) {
            return Response::error("Удалённой записи №{$input['employee_id']} нет.");
        }

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'restored' => StaffCard::full($restoreUser
                ->handle($deleted, $actor)
                ->load('departments', 'mentor', 'staffTags')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер удалённой записи.'),
        ];
    }
}
