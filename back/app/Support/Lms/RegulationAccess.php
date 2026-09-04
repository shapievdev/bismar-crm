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
 * кого он туда добавил, и суперадминистратору. Администратор в этот перечень не
 * входит намеренно: приватность, которую отменяет должность, приватностью не
 * является, — поэтому правило и живёт здесь, а не только в политике, которую
 * Gate::before ему прощает.
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
        return $regulation->members()->whereKey($this->reader->getKey())->exists();
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
                ->orWhere($table.'.author_id', $reader)
                ->orWhereExists(function (QueryBuilder $query) use ($table, $reader): void {
                    $query->selectRaw('1')
                        ->from('regulation_members')
                        ->whereColumn('regulation_members.regulation_id', $table.'.id')
                        ->where('regulation_members.user_id', $reader);
                });
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

        return [CourseVisibility::Public->value, $this->reader->getKey(), $this->reader->getKey()];
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
                $query->where('author_id', $reader)
                    ->orWhereExists(function (QueryBuilder $query) use ($reader): void {
                        $query->selectRaw('1')
                            ->from('regulation_members')
                            ->whereColumn('regulation_members.regulation_id', 'regulations.id')
                            ->where('regulation_members.user_id', $reader);
                    });
            });
        }

        return $query->pluck('id')->map(intval(...))->all();
    }

    /**
     * Суперадминистратор — и только он: остальные видят закрытый регламент
     * лишь по имени в списке доступа.
     */
    public function seesEverything(): bool
    {
        return $this->reader->accessLevel() === AccessLevel::SuperAdmin;
    }
}
