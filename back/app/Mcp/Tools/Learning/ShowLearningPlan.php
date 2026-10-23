<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Learning;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Models\LearningPlanItem;
use App\Support\Lms\LearningPlan;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * План обучения сотрудника: что назначено и в каком порядке.
 *
 * Порядок здесь — запрет, а не совет: курс открывается, когда до него дошла
 * очередь. Поэтому в ответе и «нынешний шаг»: пока он не закрыт, остальные для
 * сотрудника заперты, и объяснять ему «почему не открывается шестой урок»
 * надо этим.
 *
 * Читать чужой план позволяет `enrollments.manage`; менять — только
 * администратору, и это другой инструмент.
 */
#[Name('learning_plan_show')]
#[Title('План обучения сотрудника')]
#[Description('Показывает план обучения сотрудника: шаги по порядку (курсы и материалы), что из них пройдено, какой шаг сейчас открыт и закончен ли план. Порядок — запрет: следующий шаг открывается, когда закрыт предыдущий. Доступные для назначения материалы перечисляет learning_plan_material.')]
final class ShowLearningPlan extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageEnrollments);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageEnrollments);

        $learner = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        $items = $learner->planItems()->with('plannable', 'assignedBy')->get();
        $plan = LearningPlan::over($learner, $items);
        $current = $plan->currentStep();

        return Response::structured([
            'employee' => ['id' => $learner->getKey(), 'name' => $learner->name],
            'finished' => $plan->isFinished(),
            'current_step_id' => $current?->getKey(),
            'steps' => $items
                ->map(fn (LearningPlanItem $step): array => [
                    'step_id' => $step->getKey(),
                    'position' => $step->position,
                    // Раздел, а не имя связи: документ и справочник живут под
                    // одной связью, но на экране это разные разделы.
                    'kind' => PlannableMaterial::kindOf($step->plannable),
                    'id' => $step->plannable?->getKey(),
                    'title' => $step->plannable?->title,
                    'completed' => $plan->isCompleted($step),
                    'completed_at' => $plan->completedAt($step)?->toDateString(),
                    'assigned_by' => $step->assignedBy?->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
        ];
    }
}
