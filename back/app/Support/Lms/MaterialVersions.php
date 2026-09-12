<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Кому какая версия документа.
 *
 * Вопрос один, но задают его с трёх концов, и потому здесь три ответа: «какие
 * версии показать в переключателе», «какая откроется первой» и «какая версия
 * моя в каждом из документов сразу» — последнее спрашивает консультант, которому
 * отбирать корпус по всей базе знаний. Держать их врозь значило бы однажды
 * расширить один и забыть про остальные — и показать в переключателе то, чего
 * человеку читать не положено.
 *
 * Правила два, и они разные:
 *
 * - **Видно ли.** Открытая версия видна всем, кому открыт сам документ:
 *   посмотреть, как считают у соседей, не запрещено — иногда за этим и
 *   приходят. Закрытая видна только своим группам; автору и руководству —
 *   всегда, по тому же рассуждению, по какому им открыт закрытый документ
 *   (см. RegulationAccess).
 * - **Чья она.** Открывается первой та версия, чьи группы совпали с группами
 *   человека. Совпало несколько — берётся первая по порядку, который задал
 *   автор (решение пользователя 2026-09-12): спор решает тот, кто пишет
 *   документ, а не то, какую версию завели позже.
 *
 * Ни одна не совпала — читается общая версия, то есть сам документ. Отдельной
 * строки у неё нет и быть не должно: документ без версий не должен ничего знать
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
     * «Моя версия» по каждому документу — тоже раз на человека: корпус
     * консультанта собирается несколькими запросами за один ответ, и каждый
     * спрашивает одно и то же.
     *
     * @var array<int, array<int, int>>
     */
    private array $mine = [];

    /**
     * Версии, которые этот человек вправе открыть, — в порядке автора.
     *
     * @return Collection<int, RegulationVersion>
     */
    public function visibleTo(Regulation $regulation, User $reader): Collection
    {
        $versions = $regulation->versions()->with('groups:id,name')->get();

        if ($this->seesEverything($regulation, $reader)) {
            return $versions;
        }

        $mine = $this->groupsOf($reader);

        return $versions->filter(
            fn (RegulationVersion $version): bool => ! $version->is_private || $this->matches($version, $mine),
        )->values();
    }

    /**
     * Версия, которая открывается этому человеку первой. Null — общая.
     *
     * Считается по видимым: закрытая чужая версия не станет ничьей по
     * умолчанию просто потому, что фамилия совпала с группой.
     */
    public function defaultFor(Regulation $regulation, User $reader): ?RegulationVersion
    {
        return $this->mineAmong($this->visibleTo($regulation, $reader), $reader);
    }

    /**
     * То же, но по уже прочитанному списку.
     *
     * Экран документа спрашивает и переключатель, и «какая моя» за один ответ,
     * и читать версии дважды ради этого незачем. Возвращается строка **из
     * этого же списка**: на ней стоит признак `is_mine`, и второй её двойник
     * ушёл бы на экран без него.
     *
     * @param  Collection<int, RegulationVersion>  $versions
     */
    public function mineAmong(Collection $versions, User $reader): ?RegulationVersion
    {
        $mine = $this->groupsOf($reader);

        if ($mine === []) {
            return null;
        }

        return $versions->first(fn (RegulationVersion $version): bool => $this->matches($version, $mine));
    }

    /**
     * Открыта ли эта версия этому человеку — для маршрута, куда пришли по
     * прямой ссылке.
     */
    public function allows(RegulationVersion $version, User $reader): bool
    {
        if (! $version->is_private) {
            return true;
        }

        $regulation = $version->loadMissing('regulation')->regulation;

        if ($regulation !== null && $this->seesEverything($regulation, $reader)) {
            return true;
        }

        return $this->matches($version, $this->groupsOf($reader));
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
     * @return array<int, int> номер документа => номер его версии для этого человека
     */
    public function mineAcross(User $reader): array
    {
        $id = (int) $reader->getKey();

        if (isset($this->mine[$id])) {
            return $this->mine[$id];
        }

        $groups = $this->groupsOf($reader);

        if ($groups === []) {
            return $this->mine[$id] = [];
        }

        /*
         * Первая по порядку из совпавших — то же правило, что и у одного
         * документа, только посчитанное разом. DISTINCT ON берёт по одной
         * строке на документ в том порядке, в каком их расставил автор.
         */
        $rows = DB::table('regulation_versions')
            ->join('regulation_version_groups', 'regulation_version_groups.version_id', '=', 'regulation_versions.id')
            ->whereIn('regulation_version_groups.group_id', $groups)
            ->orderBy('regulation_versions.regulation_id')
            ->orderBy('regulation_versions.position')
            ->orderBy('regulation_versions.id')
            ->selectRaw('DISTINCT ON (regulation_versions.regulation_id) regulation_versions.regulation_id, regulation_versions.id')
            ->get();

        $mine = [];

        foreach ($rows as $row) {
            $mine[(int) $row->regulation_id] = (int) $row->id;
        }

        return $this->mine[$id] = $mine;
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

        return DB::table('regulation_versions')
            ->where('is_private', true)
            ->when($groups !== [], fn ($query) => $query->whereNotIn(
                'id',
                DB::table('regulation_version_groups')->whereIn('group_id', $groups)->select('version_id'),
            ))
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Автор документа и руководство видят все его версии — по тому же
     * рассуждению, по какому им открыт и закрытый документ.
     */
    private function seesEverything(Regulation $regulation, User $reader): bool
    {
        return $reader->accessLevel()->grantsEverything()
            || $regulation->author_id === $reader->getKey();
    }

    /**
     * @param  list<int>  $groups
     */
    private function matches(RegulationVersion $version, array $groups): bool
    {
        if ($groups === []) {
            return false;
        }

        $own = $version->relationLoaded('groups')
            ? $version->groups->modelKeys()
            : $version->groups()->pluck('groups.id')->all();

        return array_intersect(array_map(intval(...), $own), $groups) !== [];
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
}
