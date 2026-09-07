<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * Поиск, который не роняет сохранение.
 *
 * Scout обновляет индекс прямо в обработчике `saved`, и молчащий Meilisearch
 * означал бы, что автор не может сохранить курс, пока поисковый сервер не
 * поднимут. Это не та цена: индекс — производное, его пересобирают одной
 * командой, а набранный текст — нет.
 *
 * Поэтому запись в индекс из запроса делается «мягко»: не удалась — в журнал, и
 * запрос идёт дальше. Чтение защищено отдельно, см. CatalogSearch.
 *
 * Из консоли ошибка по-прежнему валит команду, и это намеренно: `scout:import`
 * запускают руками именно затем, чтобы узнать, что индекс собран, — и «собрано»
 * при упавшем сервере было бы ложью.
 *
 * Что делать, когда предупреждение в журнале всё же появилось:
 * `php artisan scout:import "App\Models\Course"` — индекс соберётся заново.
 *
 * @phpstan-ignore trait.unused
 */
trait LenientlySearchable
{
    use Searchable {
        queueMakeSearchable as protected scoutQueueMakeSearchable;
        queueRemoveFromSearch as protected scoutQueueRemoveFromSearch;
    }

    /**
     * @param  Collection<int, static>  $models
     */
    public function queueMakeSearchable($models): void
    {
        $this->withoutBreakingTheRequest(
            fn () => $this->scoutQueueMakeSearchable($models),
            'обновить',
        );
    }

    /**
     * @param  Collection<int, static>  $models
     */
    public function queueRemoveFromSearch($models): void
    {
        $this->withoutBreakingTheRequest(
            fn () => $this->scoutQueueRemoveFromSearch($models),
            'почистить',
        );
    }

    private function withoutBreakingTheRequest(callable $write, string $what): void
    {
        if (app()->runningInConsole()) {
            $write();

            return;
        }

        try {
            $write();
        } catch (Throwable $failure) {
            Log::warning("Не удалось {$what} поисковый индекс — материал сохранён, индекс отстал.", [
                'model' => static::class,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
