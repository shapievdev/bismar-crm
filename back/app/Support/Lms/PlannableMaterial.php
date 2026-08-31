<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Regulation;
use App\Models\User;
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
     * Виды и их короткие имена — те же, что в карте AppServiceProvider: под
     * этими именами шаги лежат в базе и приходят с экрана.
     *
     * @var array<string, class-string<Model>>
     */
    public const KINDS = [
        'course' => Course::class,
        'regulation' => Regulation::class,
    ];

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
        return [
            ...$this->rows('course', $this->courses($actor), $this->courses($learner)->modelKeys()),
            ...$this->rows('regulation', $this->regulations($actor), $this->regulations($learner)->modelKeys()),
        ];
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
    private function regulations(User $user): Collection
    {
        return Regulation::query()
            ->visibleTo($user)
            ->published()
            ->with('category')
            ->orderByRaw('title COLLATE "und-x-icu"')
            ->get();
    }
}
