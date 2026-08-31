<?php

declare(strict_types=1);

namespace App\Http\Resources\Structure;

use App\Enums\DepartmentRole;
use App\Http\Resources\PersonResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Человек в отделе: то же лицо, что и в любом списке людей, плюс роль.
 *
 * Роль берётся из связующей строки, а не из самого человека: в одном отделе он
 * руководитель, в другом — рядовой сотрудник, и это про место, а не про него.
 *
 * @mixin User
 */
final class DepartmentPersonResource extends PersonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Кандидат в отдел приходит без связующей строки: роли у него ещё
        // нет, и выдумывать ей значение было бы обещанием, которого никто не
        // давал.
        $role = $this->resource->getAttribute('pivot')?->role;

        return [
            ...parent::toArray($request),

            'role' => $role === null ? null : DepartmentRole::from((string) $role)->value,
            'role_label' => $role === null ? null : DepartmentRole::from((string) $role)->label(),
        ];
    }
}
