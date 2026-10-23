<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Learning;

use App\Actions\Lms\SyncLearningPlan;
use App\Mcp\Tools\BismarTool;
use App\Models\User;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * План сотрудника целиком: порядок присланного и есть порядок шагов.
 *
 * Менять чужой план — дело должности, а не отмеченного права: читать планы
 * позволяет `enrollments.manage`, а решать, что человеку проходить, —
 * администратору (решение пользователя 2026-08-30). То же правило, что в
 * UpdateLearningPlanRequest::authorize.
 *
 * Назначить можно только то, что видишь сам. Иначе чужой закрытый материал
 * попадал бы в план по угаданному номеру — и сотрудник увидел бы название,
 * которое ему закрыто.
 *
 * Пустой список — это «убрать весь план», и отличить его от «поля не прислали»
 * иначе нечем, поэтому он обязателен.
 */
#[Name('learning_plan_set')]
#[Title('Составить план обучения')]
#[Description('Задаёт план обучения сотрудника целиком: порядок присланных шагов и есть порядок плана, а чего в списке нет, из плана убирается. Пустой список снимает план полностью. Шаг — пара «вид и номер» из learning_plan_material. Доступно администратору платформы.')]
final class SetLearningPlan extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->isAdministrator();
    }

    public function handle(Request $request, SyncLearningPlan $syncPlan): ResponseFactory|Response
    {
        $actor = $this->permitAdministrator($request);

        $input = Validator::make($request->all(), [
            'employee_id' => ['required', 'integer'],
            'items' => ['present', 'array'],
            'items.*.kind' => ['required', 'string', 'in:'.implode(',', array_keys(PlannableMaterial::KINDS))],
            'items.*.id' => ['required', 'integer', 'min:1'],
        ], [
            'items.present' => 'Пришлите список шагов. Пустой список снимает план целиком.',
            'items.*.kind.in' => 'Шагом плана бывает course, document или handbook.',
        ])->validate();

        $learner = $this->person((int) $input['employee_id']);

        /** @var list<array{kind: string, id: int}> $wanted */
        $wanted = array_values(array_map(
            static fn (array $item): array => ['kind' => (string) $item['kind'], 'id' => (int) $item['id']],
            $input['items'],
        ));

        $unseen = $this->materialTheActorCannotSee($actor, $wanted);

        if ($unseen !== []) {
            return Response::error('В плане есть материал, которого вы не видите: '
                .implode(', ', $unseen).'. Назначить можно только то, что открыто вам самому.');
        }

        $syncPlan->handle($learner, array_map(
            static fn (array $item): array => [
                // Экран присылает раздел, а связь у документа и справочника
                // одна: вид материала записан в нём самом, и вторая копия того
                // же в плане однажды разошлась бы с первой.
                'type' => PlannableMaterial::morphAliasFor($item['kind']),
                'id' => $item['id'],
            ],
            $wanted,
        ), $actor);

        return Response::structured([
            'employee' => ['id' => $learner->getKey(), 'name' => $learner->name],
            'steps' => count($wanted),
            'hint' => 'Как план выглядит у сотрудника, покажет learning_plan_show.',
        ]);
    }

    /**
     * Что из присланного составителю не видно — по разделам, как и в форме.
     *
     * @param  list<array{kind: string, id: int}>  $wanted
     * @return list<string>
     */
    private function materialTheActorCannotSee(User $actor, array $wanted): array
    {
        $unseen = [];

        foreach (array_keys(PlannableMaterial::KINDS) as $kind) {
            $ids = array_values(array_map(
                static fn (array $item): int => $item['id'],
                array_filter($wanted, static fn (array $item): bool => $item['kind'] === $kind),
            ));

            if ($ids === []) {
                continue;
            }

            $found = PlannableMaterial::queryFor($kind)
                ->visibleTo($actor)
                ->whereKey($ids)
                ->pluck('id')
                ->map(intval(...))
                ->all();

            foreach (array_diff($ids, $found) as $missing) {
                $unseen[] = $kind.' №'.$missing;
            }
        }

        return $unseen;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
            'items' => $schema->array()
                ->required()
                ->items($schema->object([
                    'kind' => $schema->string()
                        ->required()
                        ->enum(array_keys(PlannableMaterial::KINDS))
                        ->description('Вид шага: course, document или handbook.'),
                    'id' => $schema->integer()->required()->description('Номер материала.'),
                ]))
                ->description('Шаги плана по порядку. Пустой список снимает план целиком.'),
        ];
    }
}
