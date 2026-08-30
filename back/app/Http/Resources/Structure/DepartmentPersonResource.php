<?php

declare(strict_types=1);

namespace App\Http\Resources\Structure;

use App\Enums\DepartmentRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Человек в отделе: то, чем его показывают в карточке и в списке людей.
 *
 * Роль берётся из связующей строки, а не из самого человека: в одном отделе он
 * руководитель, в другом — рядовой сотрудник, и это про место, а не про него.
 *
 * @mixin User
 */
final class DepartmentPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Кандидат в отдел приходит без связующей строки: роли у него ещё
        // нет, и выдумывать ей значение было бы обещанием, которого никто не
        // давал.
        $pivot = $this->resource->getAttribute('pivot');
        $role = $pivot === null ? null : DepartmentRole::from((string) $pivot->role);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->shortName,

            // «Должность не указана» пишет интерфейс: пустое поле — это пустое
            // поле, а не строка с извинением.
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatarUrl(),

            'role' => $role?->value,
            'role_label' => $role?->label(),
        ];
    }
}
