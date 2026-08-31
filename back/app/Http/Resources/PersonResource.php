<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Человек так, как его показывают в списках людей: карточка отдела, состав
 * группы, подсказка поиска.
 *
 * Ни прав, ни почты, ни телефона: это не учётная запись сотрудника (для неё
 * есть UserResource), а лицо с именем — чтобы его узнали и выбрали.
 *
 * @mixin User
 */
class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->shortName,

            // «Должность не указана» пишет интерфейс: пустое поле — это пустое
            // поле, а не строка с извинением.
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatarUrl(),
        ];
    }
}
