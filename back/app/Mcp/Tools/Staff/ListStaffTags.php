<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Models\StaffTag;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Справочник ручных тегов.
 *
 * Открыт всякому, кто видит людей: тег — часть карточки, и без названий список
 * в ней был бы набором номеров. Тегов по стажу здесь нет и быть не может — они
 * считаются из даты приёма на лету (App\Enums\TenureTag).
 */
#[Name('staff_tags_list')]
#[Title('Теги сотрудников')]
#[Description('Справочник ручных тегов сотрудников — «Кадровый резерв», «Испытательный продлён» и прочие, какие завела компания. Номера из него нужны инструменту staff_tags_assign. Теги по стажу («стажёр», «старожил») здесь не живут: они считаются из даты приёма.')]
final class ListStaffTags extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allowsAny(Permission::ViewUsers, Permission::ManageStaffTags);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permitAny($request, Permission::ViewUsers, Permission::ManageStaffTags);

        return Response::structured([
            'tags' => StaffTag::query()->ordered()->get()
                ->map(static fn (StaffTag $tag): array => [
                    'id' => $tag->getKey(),
                    'name' => $tag->name,
                ])->all(),
        ]);
    }
}
