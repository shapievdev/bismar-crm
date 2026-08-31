<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Группа сотрудников — как её показывают в списке и в карточке.
 *
 * Состав едет только там, где его раскрыли: в списке из тридцати групп хватает
 * числа людей, а перечислять их все значило бы присылать половину штата ради
 * одной строки.
 *
 * @mixin Group
 */
final class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,

            // Считает база: `people_count` приходит из withCount, а не из
            // длины присланного списка, которого в списке групп нет.
            'people_count' => (int) ($this->people_count ?? 0),

            // Роли внутри группы нет: она плоский список, и людей показывает
            // тот же PersonResource, что и подсказка поиска.
            'people' => PersonResource::collection($this->whenLoaded('people')),
        ];
    }
}
