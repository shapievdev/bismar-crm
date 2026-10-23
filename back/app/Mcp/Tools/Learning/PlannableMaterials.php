<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Learning;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Что можно поставить шагом плана этому сотруднику.
 *
 * Отбор идёт по тому, что открыто **составителю**: чужой закрытый курс не
 * должен всплыть даже названием. А признак «видно ли это сотруднику» говорит о
 * другом — назначить закрытый от него курс не запрещено (сначала назначить,
 * потом впустить — обычный порядок), но знать об этом надо сразу, а не
 * обнаруживать, что шаг у человека молча пропал.
 */
#[Name('learning_plan_material')]
#[Title('Что можно назначить')]
#[Description('Перечисляет курсы, документы и справочники, которые можно поставить шагом плана обучения этому сотруднику, с категорией у каждого. Признак is_visible_to_learner говорит, видит ли материал сам сотрудник: назначить закрытый от него можно, но тогда шаг у него не откроется, пока его не впустят.')]
final class PlannableMaterials extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageEnrollments);
    }

    public function handle(Request $request, PlannableMaterial $material): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageEnrollments);

        $learner = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        return Response::structured([
            'employee' => ['id' => $learner->getKey(), 'name' => $learner->name],
            'material' => $material->catalogue($actor, $learner),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника, которому составляют план.'),
        ];
    }
}
