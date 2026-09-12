<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Что можно поставить шагом плана обучения.
 *
 * Список, а не поиск: план составляют, глядя на то, что вообще есть, — курсов и
 * регламентов в компании десятки, и заставлять угадывать название, чтобы
 * найти материал, значит требовать знать ответ до вопроса. Отсюда и категория у
 * каждой строки: ею список сужают до нужного раздела.
 *
 * Видимость считается дважды и намеренно. Отбор идёт по тому, что открыто
 * **составителю**: чужой закрытый курс не должен всплыть в списке даже
 * названием. А признак `is_visible_to_learner` говорит про **сотрудника** —
 * назначить закрытый от него курс не запрещено (сначала назначить, потом
 * впустить — обычный порядок), но сказать об этом надо сразу, а не оставлять
 * шаг молча пропадать у него в плане.
 */
final class PlannableMaterial
{
    /**
     * Что бывает шагом плана — глазами экрана.
     *
     * Документ и справочник — одна модель и один вид полиморфной связи
     * (`regulation`), но два разных раздела: разослать сотруднику ссылку в
     * чужой раздел значит отправить его в «не найдено». Поэтому наружу они
     * ходят порознь, а в базу ложатся одинаково — см. morphAliasFor().
     *
     * @var array<string, class-string<Model>>
     */
    public const KINDS = [
        'course' => Course::class,
        'document' => Regulation::class,
        'handbook' => Regulation::class,
    ];

    /**
     * Под каким именем вид лежит в базе. Карта — та же, что в AppServiceProvider.
     */
    public static function morphAliasFor(string $kind): string
    {
        return $kind === 'course' ? 'course' : 'regulation';
    }

    /**
     * Чем шаг является для экрана: курсом, документом или справочником.
     */
    public static function kindOf(?Model $item): string
    {
        return $item instanceof Regulation ? $item->kind->value : 'course';
    }

    /**
     * Выборка одного вида — с отбором по разделу там, где он есть.
     *
     * @return Builder<Course>|Builder<Regulation>
     */
    public static function queryFor(string $kind): Builder
    {
        if ($kind === 'course') {
            return Course::query();
        }

        return Regulation::query()->ofKind(MaterialKind::from($kind));
    }

    /**
     * @return list<array{
     *     kind: string,
     *     id: int,
     *     title: string,
     *     slug: string,
     *     category: ?string,
     *     is_visible_to_learner: bool
     * }>
     */
    public function catalogue(User $actor, User $learner): array
    {
        $rows = $this->rows('course', $this->courses($actor), $this->courses($learner)->modelKeys());

        // Документы и справочники — двумя списками, а не одним: назначая план,
        // смотрят «что из правил» и «что из справок» по отдельности.
        foreach (MaterialKind::cases() as $kind) {
            $rows = [...$rows, ...$this->rows(
                $kind->value,
                $this->materials($actor, $kind),
                $this->materials($learner, $kind)->modelKeys(),
            )];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Course|Regulation>  $offered
     * @param  list<int|string>  $openToLearner  номера того, что сотрудник откроет
     * @return list<array<string, mixed>>
     */
    private function rows(string $kind, Collection $offered, array $openToLearner): array
    {
        $seen = array_map(intval(...), $openToLearner);

        return $offered->map(fn (Course|Regulation $item): array => [
            'kind' => $kind,
            'id' => (int) $item->getKey(),
            'title' => (string) $item->title,
            'slug' => (string) $item->slug,

            // Название раздела, а не его номер: список сужают глазами, и
            // «Продажи» в отборе понятнее, чем `category_id = 4`.
            'category' => $item->category?->name,

            'is_visible_to_learner' => in_array((int) $item->getKey(), $seen, strict: true),
        ])->all();
    }

    /**
     * @return Collection<int, Course>
     */
    private function courses(User $user): Collection
    {
        // Раздел, закрытый этому человеку, для него пуст. Спрашивается это и у
        // составителя, и у сотрудника: первому не показывают того, чего он не
        // вправе открыть сам, второму — ставят признак «не увидит», чтобы шаг
        // не пропал у него в плане молча.
        if ($user->cannot(Permission::ViewCourses->value)) {
            return new Collection;
        }

        // Только опубликованные: назначать черновик значит назначать то, чего
        // сотрудник не откроет, — и с чем ничего не поделает.
        return Course::query()
            ->visibleTo($user)
            ->openToLearners()
            ->with('category')
            ->orderByRaw('title COLLATE "und-x-icu"')
            ->get();
    }

    /**
     * @return Collection<int, Regulation>
     */
    private function materials(User $user, MaterialKind $kind): Collection
    {
        if ($user->cannot($kind->viewPermission()->value)) {
            return new Collection;
        }

        return Regulation::query()
            ->ofKind($kind)
            ->visibleTo($user)
            ->published()
            ->with('category')
            ->orderByRaw('title COLLATE "und-x-icu"')
            ->get();
    }
}
