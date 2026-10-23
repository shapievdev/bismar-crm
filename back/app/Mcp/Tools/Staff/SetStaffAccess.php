<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\SyncUserAccess;
use App\Enums\AccessLevel;
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
 * Уровень доступа и права — одним решением.
 *
 * Вместе, потому что это одно решение и есть: поднять человека до
 * администратора значит обессмыслить его галочки, опустить обратно — оставить
 * галочки единственным, что у него есть. Кто кого вправе двигать по лестнице,
 * решает SyncUserAccess, и здесь это не повторяется.
 */
#[Name('staff_set_access')]
#[Title('Выдать доступ сотруднику')]
#[Description('Задаёт уровень доступа сотрудника (super-admin, admin, user) и список его прав разом. Права перечисляются целиком: что не перечислено, то снимается. У администратора и суперадминистратора права не отмечаются — им доступно всё по уровню. Список возможных прав есть в ответе whoami.')]
final class SetStaffAccess extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, SyncUserAccess $syncAccess): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageUsers);

        $input = $request->validate([
            'employee_id' => ['required', 'integer'],
            'access_level' => ['required', 'in:'.implode(',', AccessLevel::values())],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:'.implode(',', Permission::values())],
        ]);

        $person = $this->person((int) $input['employee_id']);

        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($input['permissions'] ?? []));

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'updated' => StaffCard::full($syncAccess
                ->handle($person, AccessLevel::from((string) $input['access_level']), $permissions, $actor)
                ->load('departments', 'mentor', 'staffTags')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
            'access_level' => $schema->string()
                ->required()
                ->enum(AccessLevel::class)
                ->description('Уровень: super-admin — распоряжается платформой, admin — всё, кроме назначения суперадминистраторов, user — только отмеченные права.'),
            'permissions' => $schema->array()
                ->items($schema->string()->enum(Permission::class))
                ->description('Полный список прав для уровня user: что не перечислено, то снимается. Для admin и super-admin не нужен — им доступно всё.'),
        ];
    }
}
