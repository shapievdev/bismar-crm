<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Ai\EmbedTranscriptSegments;
use App\Support\Ai\Embedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Считает векторы кусков документа — то же, что EmbedLesson делает для урока.
 *
 * Таблицы «вопрос — ответ — источник» у документа нет, поэтому считать здесь
 * нечего, кроме нарезки текста: правило само себе ответ.
 *
 * Всё остальное — рассуждение о том, почему из интерфейса задание выполняется
 * тут же, а отказ сервиса эмбеддингов не роняет сохранение, — слово в слово как
 * у урока, см. EmbedLesson.
 */
final class EmbedRegulation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly int $regulationId) {}

    public static function dispatchIfConfigured(int $regulationId): void
    {
        if (! app(Embedder::class)->isAvailable()) {
            return;
        }

        try {
            app()->runningInConsole()
                ? self::dispatch($regulationId)
                : self::dispatchSync($regulationId);
        } catch (Throwable $exception) {
            Log::warning('Векторы документа при сохранении не посчитаны.', [
                'regulation' => $regulationId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('regulation-'.$this->regulationId))->expireAfter(300)];
    }

    public function handle(EmbedTranscriptSegments $segments): void
    {
        $segments->forRegulation($this->regulationId);
    }
}
