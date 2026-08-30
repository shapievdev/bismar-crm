<?php

declare(strict_types=1);

namespace App\Http\Resources\Structure;

use App\Enums\DepartmentRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Узел структуры — карточка отдела вместе со всем, что на ней написано.
 *
 * Дети приходят вложенными, а не плоским списком: вложенность рисует
 * интерфейс, и плоский список заставил бы его собирать иерархию заново.
 *
 * @mixin Department
 */
final class DepartmentResource extends JsonResource
{
    /** Сколько лиц показывает карточка, прежде чем свернуть остальных в «+N». */
    private const FACES = 3;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'position' => $this->position,

            // Корень — компания целиком: его не удаляют и никому не подчиняют.
            'is_root' => $this->parent_id === null,

            // Шапка карточки: кто ведёт отдел и кто его замещает.
            'heads' => DepartmentPersonResource::collection($this->peopleAs(DepartmentRole::Head)),
            'deputies' => DepartmentPersonResource::collection($this->peopleAs(DepartmentRole::Deputy)),

            /*
             * Первые несколько подчинённых — карточке, чтобы показать лица, а
             * не одно число. Весь состав отдаётся отдельно, когда открывают
             * панель: в дереве из полусотни отделов это были бы тысячи строк
             * ради трёх аватарок на каждой.
             */
            'members' => DepartmentPersonResource::collection(
                $this->peopleAs(DepartmentRole::Member)->take(self::FACES),
            ),

            // Прямые участники отдела — те, кого карточка называет подчинёнными.
            'members_count' => (int) $this->members_count,

            // Весь куст: столько людей под этим отделом вместе с вложенными.
            'people_total' => (int) $this->people_total,

            'children_count' => (int) $this->children_count,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }

    /**
     * Люди отдела в одной роли — из уже загруженного списка, без похода в базу
     * за каждой карточкой.
     *
     * @return Collection<int, User>
     */
    private function peopleAs(DepartmentRole $role): Collection
    {
        /** @var Collection<int, User> $people */
        $people = $this->people;

        return $people
            ->filter(static fn (User $person): bool => $person->getAttribute('pivot')->role === $role->value)
            ->values();
    }
}
