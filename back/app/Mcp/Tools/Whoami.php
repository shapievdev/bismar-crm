<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Permission;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Кто подключён и что ему можно.
 *
 * Первое, что стоит спросить: остальные инструменты отвечают отказом, когда
 * права не хватает, и лучше узнать об этом до того, как обещать человеку
 * увольнение сотрудника. Права здесь — действующие: администратор проходит
 * `Gate::before` и обладает всеми, ничего не отмечая.
 */
#[Name('whoami')]
#[Title('Кто я на платформе')]
#[Description('Сотрудник, от имени которого работает коннектор: имя, должность, отделы, уровень доступа и действующие права. С этого стоит начинать: остальные инструменты ограничены правами этого человека.')]
final class Whoami extends BismarTool
{
    public function handle(Request $request): ResponseFactory
    {
        $actor = $this->permitAnyone($request);

        $level = $actor->accessLevel();

        $permissions = array_values(array_filter(
            Permission::values(),
            static fn (string $permission): bool => $actor->can($permission),
        ));

        return Response::structured([
            'id' => $actor->getKey(),
            'name' => $actor->name,
            'job_title' => $actor->job_title,
            'phone' => $actor->phone,
            'email' => $actor->email,
            'access_level' => $level->value,
            'access_level_label' => $level->label(),
            // У администратора права не отмечены по одному, но действуют все —
            // и инструменты спрашивают именно действующие.
            'grants_everything' => $level->grantsEverything(),
            'permissions' => $permissions,
            'departments' => $actor->departments()
                ->orderByRaw('departments.name COLLATE "und-x-icu"')
                ->get()
                ->map(fn ($department): array => [
                    'id' => $department->getKey(),
                    'name' => $department->name,
                    'role' => $department->pivot?->role,
                ])
                ->all(),
        ]);
    }
}
