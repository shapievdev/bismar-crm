<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\Department;
use App\Models\SalesPerson;
use Illuminate\Support\Collection;

/**
 * Кто есть кто: имена из витрины, переведённые в людей и отделы компании.
 *
 * В витрине продавец — строка, как её записали в 1С: «ЛКМ Агабекова Света
 * Шагабудиновна». В платформе тот же человек — сотрудник с карточкой, а над ним
 * отдел в структуре компании. Связывает их сопоставление (`sales_people`),
 * которое ведут на экране отделов продаж.
 *
 * Отсюда два правила, ради которых этот класс и заведён.
 *
 * **Имя показывается платформенное.** Строка из 1С — запасной вариант, и
 * появляется она ровно тогда, когда сопоставления нет: это не украшение, а
 * единственный способ увидеть в отчёте того же человека, что и в карточке
 * сотрудника. Заодно несопоставленный виден сразу — по тому, что имя у него
 * «из 1С».
 *
 * **Отдел берётся из структуры компании**, а не выдумывается из названия
 * склада. Спрашивают обычно с верхних отделов — «сколько дал оптовый», — а
 * сопоставляют продавцов с нижними, поэтому отдел всегда разворачивается в
 * ветку: `Department::branchIds()` отдаёт его вместе со всеми подчинёнными.
 *
 * Сопоставление читается один раз на запрос: его спрашивают и на выдаче
 * словаря, и на каждой строке рейтинга.
 */
final class CompanyStructure
{
    /** @var Collection<string, SalesPerson>|null */
    private ?Collection $mapping = null;

    /** @var Collection<int, Department>|null */
    private ?Collection $departments = null;

    /**
     * Названия отделов — те, что есть в структуре компании.
     *
     * Уходят модели списком, как склады и каналы: «оптовый отдел» она должна
     * назвать ровно так, как он зовётся здесь, иначе отбор не совпадёт ни с
     * чем и панель выйдет пустой.
     *
     * @return list<string>
     */
    public function departmentNames(): array
    {
        return $this->allDepartments()
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * Имена из витрины, относящиеся к названным отделам.
     *
     * Вместе с подчинёнными отделами: спросив «оптовый», человек имеет в виду
     * и всё, что под ним. Пустой ответ означает «таких продавцов нет» — и это
     * честнее, чем молча показать выручку всей компании.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function sellersOf(array $names): array
    {
        $wanted = $this->branchFor($names);

        if ($wanted === []) {
            return [];
        }

        return $this->sellers()
            ->filter(static fn (SalesPerson $person): bool => $person->department_id !== null
                && in_array($person->department_id, $wanted, true))
            ->map(static fn (SalesPerson $person): string => (string) $person->external_name)
            ->values()
            ->all();
    }

    /**
     * Как показать имя из витрины: сотрудником платформы или как есть.
     *
     * Строка из 1С остаётся только там, где сопоставления нет, — ровно так, как
     * это задумано на экране отделов продаж.
     */
    public function nameFor(string $external): string
    {
        $person = $this->sellers()->get($external);
        $name = trim((string) $person?->user?->name);

        return $name === '' ? $external : $name;
    }

    /**
     * Отдел, которому принадлежит имя из витрины.
     *
     * Тот самый, с которым его сопоставили, — не вышестоящий: свёртка по ветке
     * делается отдельно и осознанно, иначе выручка считалась бы дважды.
     */
    public function departmentOf(string $external): ?string
    {
        $person = $this->sellers()->get($external);

        if ($person?->department_id === null) {
            return null;
        }

        $department = $this->allDepartments()->firstWhere('id', $person->department_id);

        return $department === null ? null : (string) $department->name;
    }

    /**
     * Ветка названных отделов: они сами и всё, что под ними.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    private function branchFor(array $names): array
    {
        $wanted = array_map(static fn (string $name): string => mb_strtolower(trim($name)), $names);

        $ids = [];

        foreach ($this->allDepartments() as $department) {
            if (in_array(mb_strtolower((string) $department->name), $wanted, true)) {
                $ids = [...$ids, ...$department->branchIds()];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return Collection<string, SalesPerson>
     */
    private function sellers(): Collection
    {
        return $this->mapping ??= SalesPerson::query()
            ->with('user:id,last_name,first_name,middle_name')
            ->get()
            ->keyBy('external_name');
    }

    /**
     * @return Collection<int, Department>
     */
    private function allDepartments(): Collection
    {
        return $this->departments ??= Department::query()->ordered()->get();
    }
}
