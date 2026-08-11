<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\CourseVisibility;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Кому какой курс открыт — одно правило на всё приложение.
 *
 * Открытый курс доступен каждому, кто вправе читать базу знаний. Приватный —
 * только тому, кто его завёл, тем, кого он туда добавил, и суперадминистратору.
 * Администратор в этот перечень не входит намеренно: приватность, которую
 * отменяет должность, приватностью не является.
 *
 * Именно поэтому правило живёт здесь, а не только в политике. Gate::before
 * пропускает администратора через любую проверку, так что политика — не то
 * место, где можно ему отказать; см. AppServiceProvider, где для курсов этот
 * пропуск снят, и EnsureCourseAccess, который закрывает маршруты, до политики
 * не доходящие.
 *
 * Право и доступ — разные вещи и складываются: доступ решает, существует ли
 * курс для этого человека, право «Редактирование курсов» — можно ли его
 * править. Добавленный в приватный курс редактор правит его наравне с автором;
 * редактор, которого не добавили, курса не видит вовсе.
 */
final class CourseAccess
{
    /**
     * Приватные курсы, открытые этому человеку. Считается один раз на запрос:
     * за один вопрос консультанту область чтения спрашивают трижды.
     *
     * @var list<int>|null
     */
    private ?array $privateIds = null;

    private function __construct(private readonly User $reader) {}

    public static function of(User $reader): self
    {
        return new self($reader);
    }

    /**
     * Открыт ли курс этому человеку.
     */
    public function allows(Course $course): bool
    {
        if (! $course->visibility->isPrivate()) {
            return true;
        }

        if ($this->seesEverything() || $course->author_id === $this->reader->getKey()) {
            return true;
        }

        // Отношением, а не перечнем всех приватных курсов: здесь спрашивают
        // про один курс, и читать ради этого весь список незачем.
        return $course->members()->whereKey($this->reader->getKey())->exists();
    }

    /**
     * Оставляет в выборке только то, что этому человеку видно.
     *
     * Условием, а не списком идентификаторов: список приходится вычитывать
     * целиком, и на человеке, добавленном в сотню курсов, запрос вырастал бы
     * на сотню чисел там, где хватает одного EXISTS по индексу.
     *
     * @param  EloquentBuilder<Course>|QueryBuilder  $query
     */
    public function applyTo(EloquentBuilder|QueryBuilder $query, string $table = 'courses'): void
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
                        ->from('course_members')
                        ->whereColumn('course_members.course_id', $table.'.id')
                        ->where('course_members.user_id', $reader);
                });
        });
    }

    /**
     * То же условие для запросов, написанных на SQL, — консультант ищет ими.
     *
     * Возвращается вместе с ведущим AND и пустым, когда ограничивать нечего,
     * чтобы вставлять его можно было в любое WHERE, не разбираясь, первое это
     * условие или пятое.
     */
    public function sqlCondition(string $table = 'courses'): string
    {
        if ($this->seesEverything()) {
            return '';
        }

        return sprintf(<<<'SQL'
             AND (%1$s.visibility = ? OR %1$s.author_id = ? OR EXISTS (
                SELECT 1 FROM course_members
                WHERE course_members.course_id = %1$s.id AND course_members.user_id = ?
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
     * Чем эта область чтения отличается от чужой.
     *
     * Ключ кэша для всего, что зависит только от набора доступных курсов, а не
     * от того, кто спрашивает. У подавляющего большинства сотрудников приватных
     * курсов нет вовсе — и все они пользуются одной записью.
     */
    public function fingerprint(): string
    {
        if ($this->seesEverything()) {
            return 'all';
        }

        $ids = $this->privateCourseIds();

        return $ids === [] ? 'public' : sha1(implode(',', $ids));
    }

    /**
     * Приватные курсы, до которых этот человек допущен.
     *
     * @return list<int>
     */
    public function privateCourseIds(): array
    {
        if ($this->privateIds !== null) {
            return $this->privateIds;
        }

        $reader = $this->reader->getKey();

        $query = DB::table('courses')
            ->where('visibility', CourseVisibility::Private->value)
            ->whereNull('deleted_at');

        if (! $this->seesEverything()) {
            $query->where(function (QueryBuilder $query) use ($reader): void {
                $query->where('author_id', $reader)
                    ->orWhereExists(function (QueryBuilder $query) use ($reader): void {
                        $query->selectRaw('1')
                            ->from('course_members')
                            ->whereColumn('course_members.course_id', 'courses.id')
                            ->where('course_members.user_id', $reader);
                    });
            });
        }

        return $this->privateIds = $query->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Суперадминистратор — и только он: остальные видят приватный курс лишь
     * по имени в списке доступа.
     */
    public function seesEverything(): bool
    {
        return $this->reader->accessLevel() === AccessLevel::SuperAdmin;
    }
}
