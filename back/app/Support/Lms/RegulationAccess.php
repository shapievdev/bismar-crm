<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\User;
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
 * Пустить можно и поимённо, и группой (2026-09-12): списки складываются, а
 * состав группы читается на каждом обращении — см. admitted().
 *
 * Отдельный класс, а не общий с курсами: у них разные таблицы допущенных, а
 * ветвление внутри по имени таблицы читалось бы хуже двух прямых правил.
 */
final class RegulationAccess
{
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

        return $this->admittedByGroup((int) $regulation->getKey());
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
     * Допущен ли читатель — поимённо или группой (2026-09-12).
     *
     * Двумя EXISTS, дописанными через OR к уже начатому условию: оба способа
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
            ))
        SQL, $table);
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
