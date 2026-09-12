<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Structure\DepartmentReach;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Кому какой регламент открыт — одно правило на всё приложение.
 *
 * Слово в слово то же рассуждение, что и о курсах (см. CourseAccess): открытый
 * регламент доступен каждому, кто читает базу знаний; закрытый — автору, тем,
 * кого он туда добавил, и руководству — администратору наравне с
 * суперадминистратором. Кого в закрытый регламент пускать, решает по-прежнему
 * автор: должность даёт прочитать, но не распоряжаться — см. decidesWhoGetsIn().
 * Правило живёт здесь, а не только в политике, которую Gate::before
 * администратору прощает.
 *
 * Пустить можно поимённо, группой и отделом (2026-09-12): списки складываются,
 * состав группы читается на каждом обращении, а отдел охватывает и всё, что под
 * ним, — см. admitted().
 *
 * Отдельный класс, а не общий с курсами: у них разные таблицы допущенных, а
 * ветвление внутри по имени таблицы читалось бы хуже двух прямых правил.
 */
final class RegulationAccess
{
    /**
     * Отделы, которыми можно позвать этого человека, — вместе со стоящими над
     * ними. Считаются один раз: см. reaching().
     *
     * @var list<int>|null
     */
    private ?array $reaching = null;

    private function __construct(private readonly User $reader) {}

    public static function of(User $reader): self
    {
        return new self($reader);
    }

    public function allows(Regulation $regulation): bool
    {
        if (! $regulation->isPrivate()) {
            return true;
        }

        if ($this->seesEverything() || $regulation->author_id === $this->reader->getKey()) {
            return true;
        }

        // Отношением, а не перечнем всех закрытых регламентов: здесь спрашивают
        // про один, и читать ради этого весь список незачем.
        if ($regulation->members()->whereKey($this->reader->getKey())->exists()) {
            return true;
        }

        if ($this->admittedByGroup((int) $regulation->getKey())) {
            return true;
        }

        return $this->admittedByDepartment((int) $regulation->getKey());
    }

    /**
     * Впущен ли человек отделом, в котором он числится, — или тем, что стоит
     * над его отделом (2026-09-12).
     */
    private function admittedByDepartment(int $regulation): bool
    {
        $departments = $this->reaching();

        if ($departments === []) {
            return false;
        }

        return DB::table('regulation_member_departments')
            ->where('regulation_id', $regulation)
            ->whereIn('department_id', $departments)
            ->exists();
    }

    /**
     * Впущен ли человек группой, в которой состоит (2026-09-12).
     *
     * Состав группы читается здесь же, а не замораживается при допуске: ушедший
     * из группы теряет материал тем же вечером, пришедший — открывает.
     */
    private function admittedByGroup(int $regulation): bool
    {
        return DB::table('regulation_member_groups')
            ->join('group_members', 'group_members.group_id', '=', 'regulation_member_groups.group_id')
            ->where('regulation_member_groups.regulation_id', $regulation)
            ->where('group_members.user_id', $this->reader->getKey())
            ->exists();
    }

    /**
     * Оставляет в выборке только то, что этому человеку видно.
     *
     * Условием, а не списком идентификаторов: список приходится вычитывать
     * целиком, и на человеке, добавленном в сотню регламентов, запрос вырос бы
     * на сотню чисел там, где хватает одного EXISTS по индексу.
     *
     * @param  EloquentBuilder<Regulation>|QueryBuilder  $query
     */
    public function applyTo(EloquentBuilder|QueryBuilder $query, string $table = 'regulations'): void
    {
        if ($this->seesEverything()) {
            return;
        }

        $reader = $this->reader->getKey();

        $query->where(function (EloquentBuilder|QueryBuilder $query) use ($table, $reader): void {
            $query->where($table.'.visibility', CourseVisibility::Public->value)
                ->orWhere($table.'.author_id', $reader);

            $this->admitted($query, $table.'.id');
        });
    }

    /**
     * Допущен ли читатель — поимённо, группой или отделом (2026-09-12).
     *
     * Тремя EXISTS, дописанными через OR к уже начатому условию: способы
     * складываются. Одним куском, чтобы список допущенных не разошёлся между
     * каталогом и перечнем закрытых документов.
     *
     * @param  EloquentBuilder<Regulation>|QueryBuilder  $query
     * @param  string  $regulationId  колонка с номером материала в объемлющем запросе
     */
    private function admitted(EloquentBuilder|QueryBuilder $query, string $regulationId): void
    {
        $reader = $this->reader->getKey();

        $query
            ->orWhereExists(function (QueryBuilder $query) use ($regulationId, $reader): void {
                $query->selectRaw('1')
                    ->from('regulation_members')
                    ->whereColumn('regulation_members.regulation_id', $regulationId)
                    ->where('regulation_members.user_id', $reader);
            })
            ->orWhereExists(function (QueryBuilder $query) use ($regulationId, $reader): void {
                $query->selectRaw('1')
                    ->from('regulation_member_groups')
                    ->join('group_members', 'group_members.group_id', '=', 'regulation_member_groups.group_id')
                    ->whereColumn('regulation_member_groups.regulation_id', $regulationId)
                    ->where('group_members.user_id', $reader);
            });

        // Отдел спрашивается от человека: строка в списке одна на отдел, а
        // охватывает он и всё, что под ним, — см. reaching().
        $departments = $this->reaching();

        if ($departments !== []) {
            $query->orWhereExists(function (QueryBuilder $query) use ($regulationId, $departments): void {
                $query->selectRaw('1')
                    ->from('regulation_member_departments')
                    ->whereColumn('regulation_member_departments.regulation_id', $regulationId)
                    ->whereIn('regulation_member_departments.department_id', $departments);
            });
        }
    }

    /**
     * Отделы, адресуясь к которым попадают в этого человека: его собственные и
     * все, что стоят над ними. Считается один раз на объект.
     *
     * @return list<int>
     */
    private function reaching(): array
    {
        return $this->reaching ??= app(DepartmentReach::class)->reaching($this->reader);
    }

    /**
     * Ветка отдела для запроса, написанного строкой.
     *
     * Пустая, когда человек не числится нигде: `IN ()` — не SQL, а условие,
     * которое никогда не сходится, лишь удлиняет запрос.
     */
    private function departmentCondition(string $owner): string
    {
        $departments = $this->reaching();

        if ($departments === []) {
            return '';
        }

        return sprintf(
            ' OR EXISTS (SELECT 1 FROM regulation_member_departments'
            .' WHERE regulation_member_departments.regulation_id = %1$s.id'
            .' AND regulation_member_departments.department_id IN (%2$s))',
            $owner,
            implode(', ', array_fill(0, count($departments), '?')),
        );
    }

    /**
     * То же правило готовым куском SQL — для запросов, которые собираются
     * строкой.
     *
     * Такой запрос один: поиск консультанта. Он читает не таблицу документов, а
     * нарезку их текста, соединённую вручную ради скорости, и построителю
     * запросов там места нет. Пересказ закрытого документа выдаёт его не хуже
     * открытой страницы, поэтому правило здесь то же самое, слово в слово.
     */
    public function sqlCondition(string $table = 'regulations'): string
    {
        if ($this->seesEverything()) {
            return '';
        }

        return sprintf(<<<'SQL'
             AND (%1$s.visibility = ? OR %1$s.author_id = ? OR EXISTS (
                SELECT 1 FROM regulation_members
                WHERE regulation_members.regulation_id = %1$s.id AND regulation_members.user_id = ?
            ) OR EXISTS (
                SELECT 1 FROM regulation_member_groups
                JOIN group_members ON group_members.group_id = regulation_member_groups.group_id
                WHERE regulation_member_groups.regulation_id = %1$s.id AND group_members.user_id = ?
            )%2$s)
        SQL, $table, $this->departmentCondition($table));
    }

    /**
     * Подстановки к sqlCondition(), в том же порядке.
     *
     * @return list<mixed>
     */
    public function sqlBindings(): array
    {
        if ($this->seesEverything()) {
            return [];
        }

        // Читатель назван трижды: автором, допущенным поимённо и участником
        // впущенной группы.
        return [
            CourseVisibility::Public->value,
            $this->reader->getKey(),
            $this->reader->getKey(),
            $this->reader->getKey(),

            // Отделы стоят последними — там же, где их вопросительные знаки,
            // см. departmentCondition().
            ...$this->reaching(),
        ];
    }

    /**
     * Приватные документы, до которых этот человек допущен.
     *
     * Нужны журналу вопросов: ответ, собранный из закрытого документа, нельзя
     * показывать тому, кому этот документ не открывали, — пересказ выдаёт его
     * не хуже страницы.
     *
     * @return list<int>
     */
    public function privateRegulationIds(): array
    {
        $reader = $this->reader->getKey();

        $query = DB::table('regulations')
            ->where('visibility', CourseVisibility::Private->value)
            ->whereNull('deleted_at');

        if (! $this->seesEverything()) {
            $query->where(function (QueryBuilder $query) use ($reader): void {
                $query->where('author_id', $reader);

                $this->admitted($query, 'regulations.id');
            });
        }

        return $query->pluck('id')->map(intval(...))->all();
    }

    /**
     * Руководство — администратор и суперадминистратор: остальные видят
     * закрытый регламент, только если их туда впустили.
     */
    public function seesEverything(): bool
    {
        return $this->reader->accessLevel()->grantsEverything();
    }

    /**
     * Кто решает, кого в закрытый регламент пускать, помимо автора.
     *
     * Только суперадминистратор — по тому же рассуждению, что и у курсов:
     * прочитать закрытое должность даёт, а круг допущенных завёл под себя
     * автор.
     */
    public function decidesWhoGetsIn(): bool
    {
        return $this->reader->accessLevel() === AccessLevel::SuperAdmin;
    }
}
