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
 * тому, кто его завёл, тем, кого он туда добавил, и руководству:
 * администратору наравне с суперадминистратором (решение пользователя
 * 2026-09-10). Приватность отгораживает курс от компании, а не от тех, кто за
 * неё отвечает.
 *
 * Добавить можно и поимённо, и группой (2026-09-12): списки складываются, а
 * состав группы читается на каждом обращении — см. admitted().
 *
 * Видеть закрытый курс и распоряжаться им — по-прежнему разные вещи: круг
 * допущенных остаётся за автором, и должность его не расширяет. Об этом
 * отдельное правило — decidesWhoGetsIn().
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

    /**
     * Чей это доступ.
     *
     * Спрашивают ради второго правила — документов: у консультанта корпус
     * общий, и строить его по двум разным людям было бы ошибкой, которую
     * ничего бы не поймало.
     */
    public function reader(): User
    {
        return $this->reader;
    }

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
        if ($course->members()->whereKey($this->reader->getKey())->exists()) {
            return true;
        }

        return $this->admittedByGroup((int) $course->getKey());
    }

    /**
     * Впущен ли человек группой, в которой состоит (2026-09-12).
     *
     * Состав группы читается здесь же, а не замораживается при допуске: ушедший
     * из группы теряет курс тем же вечером, пришедший — открывает. Ровно ради
     * этого группу и называют вместо двадцати фамилий.
     */
    private function admittedByGroup(int $course): bool
    {
        return DB::table('course_member_groups')
            ->join('group_members', 'group_members.group_id', '=', 'course_member_groups.group_id')
            ->where('course_member_groups.course_id', $course)
            ->where('group_members.user_id', $this->reader->getKey())
            ->exists();
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
                ->orWhere($table.'.author_id', $reader);

            $this->admitted($query, $table.'.id');
        });
    }

    /**
     * Допущен ли читатель — поимённо или группой (2026-09-12).
     *
     * Двумя EXISTS, дописанными через OR к уже начатому условию: оба способа
     * складываются, и вызывающему остаётся сказать только про автора и про
     * открытость. Одним куском, чтобы список допущенных не разошёлся между
     * каталогом и перечнем приватных курсов.
     *
     * @param  EloquentBuilder<Course>|QueryBuilder  $query
     * @param  string  $courseId  колонка с номером курса в объемлющем запросе
     */
    private function admitted(EloquentBuilder|QueryBuilder $query, string $courseId): void
    {
        $reader = $this->reader->getKey();

        $query
            ->orWhereExists(function (QueryBuilder $query) use ($courseId, $reader): void {
                $query->selectRaw('1')
                    ->from('course_members')
                    ->whereColumn('course_members.course_id', $courseId)
                    ->where('course_members.user_id', $reader);
            })
            ->orWhereExists(function (QueryBuilder $query) use ($courseId, $reader): void {
                $query->selectRaw('1')
                    ->from('course_member_groups')
                    ->join('group_members', 'group_members.group_id', '=', 'course_member_groups.group_id')
                    ->whereColumn('course_member_groups.course_id', $courseId)
                    ->where('group_members.user_id', $reader);
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
            ) OR EXISTS (
                SELECT 1 FROM course_member_groups
                JOIN group_members ON group_members.group_id = course_member_groups.group_id
                WHERE course_member_groups.course_id = %1$s.id AND group_members.user_id = ?
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
                $query->where('author_id', $reader);

                $this->admitted($query, 'courses.id');
            });
        }

        return $this->privateIds = $query->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Руководство — администратор и суперадминистратор: остальные видят
     * приватный курс, только если их туда впустили.
     */
    public function seesEverything(): bool
    {
        return $this->reader->accessLevel()->grantsEverything();
    }

    /**
     * Кто решает, кого в приватный курс пускать, помимо автора.
     *
     * Только суперадминистратор. Заглянуть в закрытый курс и распоряжаться
     * кругом допущенных — разные вещи: первое должность даёт, второе завёл под
     * себя автор, и отдавать это должности значило бы, что закрытого круга
     * нет вовсе.
     */
    public function decidesWhoGetsIn(): bool
    {
        return $this->reader->accessLevel() === AccessLevel::SuperAdmin;
    }
}
