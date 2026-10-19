<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Favourite;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Избранное одного человека — от строки в базе до строки на экране.
 *
 * Собрано в одном месте по той же причине, по которой собраны права разделов
 * (см. App\Enums\MaterialKind): видов материала три, и каждый отвечает на все
 * вопросы по-своему — как зовётся, где лежит, кому открыт, выложен ли. Разнести
 * эти ответы по контроллеру и ресурсу значит переписать их трижды и однажды
 * поправить два из трёх.
 *
 * Главное правило здесь одно: **закладка не открывает того, что было закрыто**.
 * Отложить можно лишь то, что человеку и так видно, а в списке остаётся лишь
 * то, что видно до сих пор. Курс, из которого его потом исключили, из избранного
 * пропадает — но строка остаётся: вернут допуск, вернётся и закладка.
 */
final class Favourites
{
    /** Виды, которые вообще можно отложить, — именами из карты полиморфных связей. */
    public const KINDS = ['course', 'lesson', 'regulation'];

    private function __construct(private readonly User $reader) {}

    public static function of(User $reader): self
    {
        return new self($reader);
    }

    /**
     * Можно ли отложить это — то есть видно ли оно этому человеку.
     *
     * Спрашивается политикой, а не правом раздела: право говорит, что человек
     * умеет делать, но не с каким материалом, — а закрытый курс закрыт и от
     * того, у кого право есть. У урока политики своей нет, и видимость у него
     * составная: курс открыт и урок выложен (см. LearningController::showLesson).
     */
    public function mayKeep(Model $material): bool
    {
        if ($material instanceof Lesson) {
            $course = $material->course();

            if ($course === null || Gate::forUser($this->reader)->denies('view', $course)) {
                return false;
            }

            // Черновик урока откладывать нечего: для читателя его ещё нет, а
            // тому, кто курс ведёт, он и так под рукой на экране правки.
            return $material->isPublished()
                || Gate::forUser($this->reader)->allows('update', $course);
        }

        return Gate::forUser($this->reader)->allows('view', $material);
    }

    /**
     * Отложено ли это у него уже.
     */
    public function keeps(Model $material): bool
    {
        return $this->rowFor($material)->exists();
    }

    /**
     * Отложить. Повторное нажатие ничего не меняет и ничем не отвечает: звезда —
     * положение, а не счётчик, и двойное нажатие не должно ломаться об уникальный
     * ключ.
     */
    public function keep(Model $material): Favourite
    {
        return Favourite::firstOrCreate([
            'user_id' => $this->reader->getKey(),
            'favouritable_type' => $material->getMorphClass(),
            'favouritable_id' => $material->getKey(),
        ]);
    }

    /** Снять закладку. Снимать нечего — тоже успех, по той же причине. */
    public function forget(Model $material): void
    {
        $this->rowFor($material)->delete();
    }

    /**
     * Проставить материалам «отложено» — одним запросом на весь список.
     *
     * Этим живёт звезда на карточке и на странице материала: спрашивать про
     * каждую строку отдельно значило бы класть в каталог из пятнадцати курсов
     * пятнадцать лишних запросов. Признак едет атрибутом модели — тем же
     * способом, которым каталог отмечает запертые планом курсы.
     *
     * @param  iterable<int, Model>  $materials
     */
    public function mark(iterable $materials): void
    {
        /** @var array<string, list<Model>> $byKind */
        $byKind = [];

        foreach ($materials as $material) {
            $byKind[$material->getMorphClass()][] = $material;
        }

        foreach ($byKind as $kind => $group) {
            $kept = Favourite::query()
                ->where('user_id', $this->reader->getKey())
                ->where('favouritable_type', $kind)
                ->whereIn('favouritable_id', array_map(static fn (Model $one) => $one->getKey(), $group))
                ->pluck('favouritable_id')
                ->flip();

            foreach ($group as $material) {
                $material->setAttribute('is_favourite', $kept->has($material->getKey()));
            }
        }
    }

    /**
     * Сколько материалов у человека отложено — для значка во вкладке.
     *
     * Считается по тем же правилам, что и список: обещать во вкладке число,
     * которого на странице не окажется, нельзя.
     */
    public function count(): int
    {
        return $this->list()->count();
    }

    /**
     * Всё отложенное, что этому человеку видно, — свежее сверху.
     *
     * Порядок здесь один, и он же единственный, который зависит от базы:
     * остальные — название, раздел — переставляют уже готовый список на самой
     * странице. Список личный и короткий, и ходить за ним на сервер ради
     * сортировки по алфавиту значило бы ждать ответа там, где нечего ждать.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        /** @var Collection<int, Favourite> $rows */
        $rows = Favourite::query()
            ->where('user_id', $this->reader->getKey())
            ->freshestFirst()
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $materials = $this->materialsFor($rows);

        $this->sweepGone($rows, $materials);

        return $rows
            ->map(function (Favourite $row) use ($materials): ?array {
                $material = $materials[$row->favouritable_type][$row->favouritable_id] ?? null;

                if ($material === null || $this->isDiscarded($material) || ! $this->mayKeep($material)) {
                    return null;
                }

                return $this->describe($row, $material);
            })
            ->filter()
            ->values();
    }

    /**
     * Лежит ли материал в корзине — сам или вместе со своим курсом.
     *
     * Выброшенное из списка уходит: ни открыть его, ни прочитать нельзя, а
     * строка с мёртвой ссылкой объясняла бы человеку меньше, чем её отсутствие.
     * Саму закладку при этом не снимают — вернут материал, вернётся и она.
     */
    private function isDiscarded(Model $material): bool
    {
        if ($material instanceof Lesson) {
            return $material->course()?->trashed() ?? true;
        }

        return method_exists($material, 'trashed') && $material->trashed();
    }

    /**
     * Материалы по отложенным строкам — вместе с теми, что лежат в корзине.
     *
     * С корзиной, потому что у строки и материала две разные судьбы: выброшенный
     * материал из списка уходит, а закладка остаётся — вернут материал, вернётся
     * и она. Отличить выброшенное от стёртого насовсем можно только так.
     *
     * @param  Collection<int, Favourite>  $rows
     * @return array<string, array<int, Model>>
     */
    private function materialsFor(Collection $rows): array
    {
        $idsOf = static fn (string $kind): array => $rows
            ->where('favouritable_type', $kind)
            ->pluck('favouritable_id')
            ->unique()
            ->all();

        $courses = $idsOf('course');
        $lessons = $idsOf('lesson');
        $regulations = $idsOf('regulation');

        return [
            'course' => $courses === [] ? [] : Course::withTrashed()
                ->with('category:id,name,slug')
                ->whereKey($courses)
                ->get()
                ->keyBy('id')
                ->all(),

            // Урок живёт курсом: без него не собрать ни адрес, ни доступ. Курс
            // при этом берётся вместе с выброшенным — урок мягко удалённого
            // курса сам никуда не девается, и решать его судьбу надо по курсу.
            'lesson' => $lessons === [] ? [] : Lesson::query()
                ->with(['module.course' => fn ($course) => $course->withTrashed()->with('category:id,name,slug')])
                ->whereKey($lessons)
                ->get()
                ->keyBy('id')
                ->all(),

            'regulation' => $regulations === [] ? [] : Regulation::withTrashed()
                ->with('category:id,name,slug')
                ->whereKey($regulations)
                ->get()
                ->keyBy('id')
                ->all(),
        ];
    }

    /**
     * Прибрать закладки на то, чего больше нет.
     *
     * Внешнего ключа у полиморфной связи не бывает, каскад на три таблицы сразу
     * не повесить, и наблюдатель всего не ловит: окончательное удаление курса
     * уносит его уроки каскадом самой базы, минуя события Eloquent. Поэтому
     * прибирают там, где и так точно известно, чего не стало, — при чтении
     * списка.
     *
     * Выброшенное в корзину здесь не трогают: оно нашлось (`withTrashed`), а
     * значит, может вернуться.
     *
     * @param  Collection<int, Favourite>  $rows
     * @param  array<string, array<int, Model>>  $materials
     */
    private function sweepGone(Collection $rows, array $materials): void
    {
        $gone = $rows
            ->filter(fn (Favourite $row): bool => ! isset($materials[$row->favouritable_type][$row->favouritable_id]))
            ->pluck('id')
            ->all();

        if ($gone !== []) {
            Favourite::whereKey($gone)->delete();
        }
    }

    /**
     * Строка списка: чем материал был, как зовётся и куда ведёт.
     *
     * Вид и раздел — разное. Вид отвечает «курс это, урок или материал», раздел
     * — «в какой части базы знаний его искать»; у документа и справочника вид
     * один на двоих, а разделы свои (см. App\Enums\MaterialKind).
     *
     * @return array<string, mixed>
     */
    private function describe(Favourite $row, Model $material): array
    {
        $common = [
            'id' => $row->getKey(),
            'material_id' => $material->getKey(),
            'kind' => $material->getMorphClass(),
            'added_at' => $row->created_at?->toIso8601String(),
        ];

        if ($material instanceof Course) {
            return $common + [
                'section' => 'courses',
                'section_label' => 'Курсы',
                'title' => $material->title,
                'summary' => $material->summary,
                'url' => '/lms/'.$material->slug,
                'category' => $material->category?->name,
                'course' => null,
                'is_published' => $material->isPublished(),
            ];
        }

        if ($material instanceof Lesson) {
            $course = $material->course();

            return $common + [
                'section' => 'courses',
                'section_label' => 'Уроки',
                'title' => $material->title,
                'summary' => null,
                'url' => $course === null ? null : '/lms/'.$course->slug.'/lessons/'.$material->slug,
                'category' => $course?->category?->name,
                // Урок в списке без курса — безымянная строка: названия уроков
                // в разных курсах повторяются («Введение», «Итоги»).
                'course' => $course?->title,
                'is_published' => $material->isPublished(),
            ];
        }

        /** @var Regulation $material */
        return $common + [
            'section' => $material->kind->section(),
            'section_label' => $material->kind->plural(),
            'title' => $material->title,
            'summary' => $material->summary,
            'url' => $material->path(),
            'category' => $material->category?->name,
            'course' => null,
            'is_published' => $material->isPublished(),
        ];
    }

    /**
     * Строка этого человека про этот материал.
     *
     * @return Builder<Favourite>
     */
    private function rowFor(Model $material): Builder
    {
        return Favourite::query()
            ->where('user_id', $this->reader->getKey())
            ->where('favouritable_type', $material->getMorphClass())
            ->where('favouritable_id', $material->getKey());
    }
}
