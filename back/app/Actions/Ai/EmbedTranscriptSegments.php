<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Models\TranscriptSegment;
use App\Support\Ai\Embedder;
use App\Support\Ai\ModelSettings;
use App\Support\Ai\Vector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Считает и сохраняет векторы кусков расшифровок.
 *
 * Пересчитывает только те, у которых вектора нет или он посчитан другой
 * моделью: векторы разных моделей несравнимы, и смешивать их — значит молча
 * испортить ранжирование.
 *
 * Заменила собой EmbedPassages: корпус тот же по устройству, но шире по
 * содержанию — в него вошли запись и приложенные документы.
 */
final readonly class EmbedTranscriptSegments
{
    public function __construct(
        private Embedder $embedder,
        private ModelSettings $settings,
    ) {}

    /**
     * @return int сколько кусков получили вектор
     */
    public function handle(?int $lessonId = null, bool $force = false): int
    {
        if (! $this->embedder->isAvailable()) {
            return 0;
        }

        $model = (string) $this->settings->embeddingModel();
        $done = 0;

        $this->stale($model, $lessonId, $force)
            ->with('lesson.module.course:id,title')
            ->chunkById(64, function (Collection $segments) use ($model, &$done): void {
                // Вектор считается по названию курса, заголовку и тексту
                // вместе: кусок из середины записи сам по себе не говорит, о
                // чём он, а название курса — половина его смысла.
                $texts = $segments
                    ->map(static fn (TranscriptSegment $segment): string => implode("\n", array_filter([
                        $segment->lesson?->module?->course?->title,
                        $segment->heading,
                        $segment->content,
                    ])))
                    ->values()
                    ->all();

                foreach ($this->embedder->embed($texts) as $index => $vector) {
                    $segment = $segments->values()->get($index);

                    $segment?->forceFill([
                        'embedding' => Vector::pack($vector),
                        'embedding_model' => $model,
                    ])->save();

                    $done++;
                }
            });

        return $done;
    }

    /**
     * @return Builder<TranscriptSegment>
     */
    private function stale(string $model, ?int $lessonId, bool $force): Builder
    {
        return TranscriptSegment::query()
            ->when($lessonId !== null, static fn (Builder $query) => $query->where('lesson_id', $lessonId))
            ->unless($force, static fn (Builder $query) => $query->where(
                static function (Builder $query) use ($model): void {
                    $query->whereNull('embedding')->orWhere('embedding_model', '!=', $model);
                },
            ));
    }
}
