<?php

declare(strict_types=1);

namespace App\Support\Lms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * Поиск по каталогу курсов и документов.
 *
 * Слова ищет Meilisearch, а кому что показывать — по-прежнему решает база.
 * Поисковик возвращает только номера найденного, и дальше выборка проходит все
 * те же условия: закрытый материал остаётся закрытым, черновик — черновиком.
 * Складывать доступ в индекс значило бы держать правила о нём в двух местах и
 * однажды поправить их в одном.
 *
 * Порядок ставится по релевантности — в том, в каком номера вернул поисковик.
 * Сортировка каталога добавляется после и работает внутри равных: у совпавших
 * по-разному «свежее» ничего не значит.
 *
 * Поисковик не поднят или молчит — ищем по-старому, обычным ILIKE. Каталог без
 * поиска бесполезен, а падать из-за необязательной службы он не должен.
 */
final class CatalogSearch
{
    /**
     * Сколько номеров берём у поисковика.
     *
     * Свой предел, а не умолчание Meilisearch: там он двадцать, и каталог молча
     * терял бы всё, что не попало в первую двадцатку. Двести — заведомо больше,
     * чем осмысленно пролистать; что дальше, уточняют словом, а не листанием.
     */
    private const CANDIDATES = 200;

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $found = $this->found($query->getModel(), $term);

        if ($found === null) {
            $query->matching($term);

            return;
        }

        if ($found === []) {
            $query->whereRaw('false');

            return;
        }

        $query->whereIn($query->getModel()->getQualifiedKeyName(), $found);

        // Порядком, в котором вернул поисковик: `whereIn` о нём не помнит, а
        // без этого самое подходящее оказалось бы на третьей странице.
        $query->orderByRaw(
            sprintf('array_position(ARRAY[%s]::bigint[], %s)',
                implode(',', array_map(intval(...), $found)),
                $query->getModel()->getQualifiedKeyName(),
            ),
        );
    }

    /**
     * Номера найденного — или null, если спросить было не у кого.
     *
     * @return list<int>|null
     */
    private function found(Model $model, string $term): ?array
    {
        $driver = config('scout.driver');

        // Драйвер `null` — это «поиска нет», а не «ничего не нашлось»: его
        // движок отвечает пустотой на любой вопрос, и каталог на нём выглядел
        // бы сломанным. Двумя значениями, потому что `env()` превращает строку
        // «null» в настоящий null, а Scout — обратно в имя драйвера.
        if ($driver === null || $driver === 'null') {
            return null;
        }

        if (! in_array(Searchable::class, class_uses_recursive($model), true)) {
            return null;
        }

        try {
            /** @var Collection<int, mixed> $keys */
            $keys = $model::search($term)->take(self::CANDIDATES)->keys();

            return $keys->map(intval(...))->all();
        } catch (Throwable $failure) {
            // Тихо для человека, громко в журнале: он спрашивал про курсы, а не
            // про поисковый сервер, и ответ по названию лучше пустого экрана.
            Log::warning('Поиск через Meilisearch не удался, ищем по названию.', [
                'model' => $model::class,
                'error' => $failure->getMessage(),
            ]);

            return null;
        }
    }
}
