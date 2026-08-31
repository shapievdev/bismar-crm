<?php

declare(strict_types=1);

namespace App\Support\Structure;

use App\Models\Department;
use App\Models\User;

/**
 * Кого охватывает отдел — и какие отделы охватывают человека.
 *
 * Адресуясь отделу, адресуются и всему, что под ним: рассылка «складу» касается
 * его подотделов, иначе их пришлось бы перечислять руками, а завтра появится
 * новый и о нём забудут. Отсюда два взгляда на одно и то же родство:
 *
 *  - `branch()` смотрит сверху вниз — «кто входит в этот отдел», когда известен
 *    адресат и нужны люди;
 *  - `reaching()` смотрит снизу вверх — «какими отделами можно позвать этого
 *    человека», когда известен читатель и нужно сузить список новостей.
 *
 * Справочник берётся одним запросом и дальше живёт в памяти: отделов в компании
 * десятки, и рекурсивный запрос ради такого — из пушки по воробьям. Экземпляр
 * поэтому недолгий — на один запрос к приложению.
 */
final class DepartmentReach
{
    /**
     * Родитель у каждого отдела: id => parent_id.
     *
     * @var array<int, int|null>|null
     */
    private ?array $parents = null;

    /**
     * Отделы вместе со всем, что под ними.
     *
     * @param  list<int>  $departmentIds
     * @return list<int>
     */
    public function branch(array $departmentIds): array
    {
        $branch = array_values(array_unique(array_map(intval(...), $departmentIds)));

        if ($branch === []) {
            return [];
        }

        // Идём сверху вниз по всему справочнику, пока добавляется хоть что-то:
        // дерево неглубокое, и проходов выходит столько же, сколько уровней.
        do {
            $added = false;

            foreach ($this->parents() as $id => $parent) {
                if ($parent !== null && in_array($parent, $branch, true) && ! in_array($id, $branch, true)) {
                    $branch[] = $id;
                    $added = true;
                }
            }
        } while ($added);

        return $branch;
    }

    /**
     * Отделы, адресуясь к которым попадают в этого человека: его собственные и
     * все, что стоят над ними.
     *
     * @return list<int>
     */
    public function reaching(User $user): array
    {
        /** @var list<int> $own */
        $own = $user->departments()->pluck('departments.id')->map(intval(...))->all();

        $reaching = [];

        foreach ($own as $id) {
            $current = $id;

            while ($current !== null && ! in_array($current, $reaching, true)) {
                $reaching[] = $current;
                $current = $this->parents()[$current] ?? null;
            }
        }

        return $reaching;
    }

    /**
     * @return array<int, int|null>
     */
    private function parents(): array
    {
        if ($this->parents !== null) {
            return $this->parents;
        }

        /** @var array<int, int|null> $parents */
        $parents = [];

        foreach (Department::query()->pluck('parent_id', 'id') as $id => $parent) {
            $parents[(int) $id] = $parent === null ? null : (int) $parent;
        }

        return $this->parents = $parents;
    }
}
