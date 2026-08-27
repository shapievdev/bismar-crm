<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
 * ветвление внутри по имени таблицы читалось бы хуже двух прямых правил. Того,
 * ради чего у CourseAccess есть sqlCondition() и fingerprint(), здесь нет:
 * консультант ищет по учебным материалам и регламентов не читает.
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
     * Суперадминистратор — и только он: остальные видят закрытый регламент
     * лишь по имени в списке доступа.
     */
    public function seesEverything(): bool
    {
        return $this->reader->accessLevel() === AccessLevel::SuperAdmin;
    }
}
