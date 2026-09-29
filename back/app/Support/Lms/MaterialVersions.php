<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Lesson;
use App\Models\MaterialVersion;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Structure\DepartmentReach;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Кому какая версия материала.
 *
 * Материал здесь — документ, справочник (2026-09-12) или урок курса
 * (2026-09-25): правило у них одно, и второй его список для уроков однажды
 * разошёлся бы с первым.
 *
 * Вопрос один, но задают его с трёх концов, и потому здесь три ответа: «какие
 * версии показать в переключателе», «какая откроется первой» и «какая версия
 * моя в каждом из материалов сразу» — последнее спрашивает консультант, которому
 * отбирать корпус по всей базе знаний. Держать их врозь значило бы однажды
 * расширить один и забыть про остальные — и показать в переключателе то, чего
 * человеку читать не положено.
 *
 * Правила два, и они разные:
 *
 * - **Видно ли.** Открытая версия видна всем, кому открыт сам материал:
 *   посмотреть, как считают у соседей, не запрещено — иногда за этим и
 *   приходят. Закрытая видна только своим группам; автору и руководству —
 *   всегда, по тому же рассуждению, по какому им открыт закрытый материал
 *   (см. RegulationAccess и CourseAccess).
 * - **Чья она.** Открывается первой та версия, чьи группы совпали с группами
 *   человека. Совпало несколько — берётся первая по порядку, который задал
 *   автор (решение пользователя 2026-09-12): спор решает тот, кто пишет
 *   материал, а не то, какую версию завели позже.
 *
 * Ни одна не совпала — читается общая версия, то есть сам материал. Отдельной
 * строки у неё нет и быть не должно: материал без версий не должен ничего знать
 * о том, что версии бывают.
 */
final class MaterialVersions
{
    /**
     * Группы сотрудника — по одному запросу на человека за обращение к
     * приложению. Спрашивают их и переключатель, и выбор версии, и корпус
     * консультанта, причём трижды за один ответ.
     *
     * @var array<int, list<int>>
     */
    private array $groups = [];

    /**
     * Отделы, которыми можно позвать человека, — вместе со стоящими над ними
     * (2026-09-12). Спрашиваются там же и столько же раз, что и группы.
     *
     * @var array<int, list<int>>
     */
    private array $departments = [];

    /**
     * «Моя версия» по каждому материалу — тоже раз на человека и вид материала:
     * корпус консультанта собирается несколькими запросами за один ответ, и
     * каждый спрашивает одно и то же.
     *
     * @var array<int, array<string, array<int, int>>>
     */
    private array $mine = [];

    /**
     * Версии, которые этот человек вправе открыть, — в порядке автора.
     *
     * @param  Lesson|Regulation  $material  материал с версиями: документ,
     *                                       справочник или урок курса
     * @return Collection<int, MaterialVersion>
     */
    public function visibleTo(Model $material, User $reader): Collection
    {
        $versions = $material->versions()->with(['groups:id,name', 'departments:id,name'])->get();

        if ($this->seesEverything($material, $reader)) {
            return $versions;
        }

        return $versions->filter(
            fn (MaterialVersion $version): bool => ! $version->is_private || $this->matches($version, $reader),
        )->values();
    }

    /**
     * Версия, которая открывается этому человеку первой. Null — общая.
     *
     * Считается по видимым: закрытая чужая версия не станет ничьей по
     * умолчанию просто потому, что фамилия совпала с группой.
     *
     * @param  Lesson|Regulation  $material
     */
    public function defaultFor(Model $material, User $reader): ?MaterialVersion
    {
        return $this->mineAmong($this->visibleTo($material, $reader), $reader);
    }

    /**
     * То же, но по уже прочитанному списку.
     *
     * Экран документа спрашивает и переключатель, и «какая моя» за один ответ,
     * и читать версии дважды ради этого незачем. Возвращается строка **из
     * этого же списка**: на ней стоит признак `is_mine`, и второй её двойник
     * ушёл бы на экран без него.
     *
     * @param  Collection<int, MaterialVersion>  $versions
     */
    public function mineAmong(Collection $versions, User $reader): ?MaterialVersion
    {
        if ($this->groupsOf($reader) === [] && $this->departmentsOf($reader) === []) {
            return null;
        }

        return $versions->first(fn (MaterialVersion $version): bool => $this->matches($version, $reader));
    }

    /**
     * Открыта ли эта версия этому человеку — для маршрута, куда пришли по
     * прямой ссылке.
     */
    public function allows(MaterialVersion $version, User $reader): bool
    {
        if (! $version->is_private) {
            return true;
        }

        $material = $version->owner();

        if ($material !== null && $this->seesEverything($material, $reader)) {
            return true;
        }

        return $this->matches($version, $reader);
    }

    /**
     * «Моя версия» сразу во всех документах — одним запросом.
     *
     * Нужно консультанту: он ищет по всей базе знаний, и спрашивать про версию
     * у каждого найденного куска значило бы ходить в базу столько раз, сколько
     * нашлось абзацев. Отсюда же он берёт и то, какие документы для этого
     * человека вообще разделены на версии, — у остальных ему годится общий
     * текст.
     *
     * Спрашивается по виду материала: у консультанта куски документов и куски
     * уроков лежат в одной таблице, но различаются столбцом, по которому их
     * отбирают, — и складывать те и другие в один список значило бы однажды
     * вычесть урок номер три вместо документа номер три.
     *
     * @param  string  $morph  имя вида в карте морфов: `regulation` или `lesson`
     * @return array<int, int> номер материала => номер его версии для этого человека
     */
    public function mineAcross(User $reader, string $morph): array
    {
        $id = (int) $reader->getKey();

        if (isset($this->mine[$id][$morph])) {
            return $this->mine[$id][$morph];
        }

        $groups = $this->groupsOf($reader);
        $departments = $this->departmentsOf($reader);

        if ($groups === [] && $departments === []) {
            return $this->mine[$id][$morph] = [];
        }

        /*
         * Первая по порядку из совпавших — то же правило, что и у одного
         * материала, только посчитанное разом. DISTINCT ON берёт по одной
         * строке на материал в том порядке, в каком их расставил автор.
         *
         * Группа и отдел складываются: версия «моя», если совпало хоть что-то.
         * Не соединением, а двумя EXISTS — соединение по двум спискам разом
         * размножило бы строки и заставило бы их различать.
         */
        $rows = DB::table('material_versions')
            ->where('versionable_type', $morph)
            ->where(function ($query) use ($groups, $departments): void {
                $query->whereRaw('false');

                if ($groups !== []) {
                    $query->orWhereExists(fn ($inner) => $inner
                        ->selectRaw('1')
                        ->from('material_version_groups')
                        ->whereColumn('material_version_groups.version_id', 'material_versions.id')
                        ->whereIn('material_version_groups.group_id', $groups));
                }

                if ($departments !== []) {
                    $query->orWhereExists(fn ($inner) => $inner
                        ->selectRaw('1')
                        ->from('material_version_departments')
                        ->whereColumn('material_version_departments.version_id', 'material_versions.id')
                        ->whereIn('material_version_departments.department_id', $departments));
                }
            })
            ->orderBy('material_versions.versionable_id')
            ->orderBy('material_versions.position')
            ->orderBy('material_versions.id')
            ->selectRaw('DISTINCT ON (material_versions.versionable_id) material_versions.versionable_id, material_versions.id')
            ->get();

        $mine = [];

        foreach ($rows as $row) {
            $mine[(int) $row->versionable_id] = (int) $row->id;
        }

        return $this->mine[$id][$morph] = $mine;
    }

    /**
     * Закрытые версии, до которых этому человеку хода нет.
     *
     * Перечнем, а не условием: закрытых версий во всей базе единицы, и
     * вычесть их из корпуса дешевле, чем спрашивать про каждую группу в
     * поисковом запросе.
     *
     * @return list<int>
     */
    public function closedTo(User $reader): array
    {
        if ($reader->accessLevel()->grantsEverything()) {
            return [];
        }

        $groups = $this->groupsOf($reader);
        $departments = $this->departmentsOf($reader);

        return DB::table('material_versions')
            ->where('is_private', true)
            ->when($groups !== [], fn ($query) => $query->whereNotIn(
                'id',
                DB::table('material_version_groups')->whereIn('group_id', $groups)->select('version_id'),
            ))
            ->when($departments !== [], fn ($query) => $query->whereNotIn(
                'id',
                DB::table('material_version_departments')
                    ->whereIn('department_id', $departments)
                    ->select('version_id'),
            ))
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Автор материала и руководство видят все его версии — по тому же
     * рассуждению, по какому им открыт и закрытый материал.
     *
     * У урока своего автора нет: уроки пишет тот, кто ведёт курс, — значит и
     * все версии урока видит автор курса (то же рассуждение, что в
     * MaterialAppeal::forLesson).
     *
     * @param  Lesson|Regulation  $material
     */
    private function seesEverything(Model $material, User $reader): bool
    {
        if ($reader->accessLevel()->grantsEverything()) {
            return true;
        }

        $author = $material instanceof Lesson
            ? $material->owningCourse()?->author_id
            : $material->author_id;

        return $author !== null && $author === $reader->getKey();
    }

    /**
     * Написана ли версия для этого человека — группой или отделом.
     *
     * Способы складываются: совпало хоть что-то — версия его. Отдел при этом
     * охватывает и всё, что под ним: сравнивается он не с отделами человека, а
     * с теми, которыми до него можно дотянуться сверху (DepartmentReach).
     */
    private function matches(MaterialVersion $version, User $reader): bool
    {
        return $this->intersects($version, 'groups', $this->groupsOf($reader))
            || $this->intersects($version, 'departments', $this->departmentsOf($reader));
    }

    /**
     * @param  list<int>  $mine
     */
    private function intersects(MaterialVersion $version, string $relation, array $mine): bool
    {
        if ($mine === []) {
            return false;
        }

        $own = $version->relationLoaded($relation)
            ? $version->getRelation($relation)->modelKeys()
            : $version->{$relation}()->pluck($relation.'.id')->all();

        return array_intersect(array_map(intval(...), $own), $mine) !== [];
    }

    /**
     * @return list<int>
     */
    private function groupsOf(User $reader): array
    {
        $id = (int) $reader->getKey();

        return $this->groups[$id] ??= $reader->groups()
            ->pluck('groups.id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Отделы, которыми можно позвать этого человека, — его собственные и все,
     * что стоят над ними.
     *
     * @return list<int>
     */
    private function departmentsOf(User $reader): array
    {
        $id = (int) $reader->getKey();

        return $this->departments[$id] ??= app(DepartmentReach::class)->reaching($reader);
    }
}
