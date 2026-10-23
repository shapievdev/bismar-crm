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
 * Теги на одном человеке.
 *
 * Своё право, а не право на людей: заводить ярлыки и раздавать доступ — разные
 * работы, и делают их разные люди. Кадровик вешает теги, не получая при этом
 * власти над учётными записями.
 *
 * Список приходит целиком, как и с экрана: что прислали, то на человеке и
 * останется. Уволенного тегировать можно — ярлык говорит о человеке, а не о его
 * нынешнем положении.
 */
#[Name('staff_tags_assign')]
#[Title('Повесить теги на сотрудника')]
#[Description('Задаёт ручные теги сотрудника. Список передаётся целиком: что не перечислено, с человека снимается; пустой список снимает все. Номера тегов даёт staff_tags_list. Записывается, кто повесил.')]
final class AssignStaffTags extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageStaffTags);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageStaffTags);

        $input = $request->validate([
            'employee_id' => ['required', 'integer'],
            'tags' => ['present', 'array'],
            'tags.*' => ['integer', 'exists:staff_tags,id'],
        ]);

        $person = $this->person((int) $input['employee_id']);

        /** @var list<int> $tags */
        $tags = array_values(array_unique(array_map(intval(...), $input['tags'])));

        // Кто повесил — записывается на каждую связь: через полгода «почему он
        // в резерве» спрашивают у того, кто это решил.
        $person->staffTags()->sync(array_fill_keys($tags, ['assigned_by_id' => $actor->getKey()]));

        return Response::structured([
            'updated' => StaffCard::full(
                $person->load('roles', 'permissions', 'departments', 'mentor', 'staffTags'),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
            'tags' => $schema->array()
                ->required()
                ->items($schema->integer())
                ->description('Полный список номеров тегов. Пустой массив снимает все теги.'),
        ];
    }
}
