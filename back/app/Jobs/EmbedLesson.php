<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Ai\EmbedLessonAnswers;
use App\Actions\Ai\EmbedTranscriptSegments;
use App\Support\Ai\Embedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Считает векторы урока: и кусков его расшифровок, и строк его таблицы.
 *
 * Сохранил на сайте — векторы посчитаны, без запущенного отдельно работника
 * очереди и без команд руками. Поэтому при сохранении из интерфейса задание
 * выполняется тут же, в том же запросе, — см. dispatchIfConfigured().
 *
 * Отказ сервиса эмбеддингов при этом сохранению не мешает: вектор появится при
 * следующем сохранении или по команде `lms:reindex`, а до тех пор материал
 * ищется словами. Терять написанное автором из-за недоступности постороннего
 * сервиса недопустимо.
 */
final class EmbedLesson implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Сервис эмбеддингов бывает недоступен минутами, а не секундами. */
    public int $backoff = 60;

    public function __construct(private readonly int $lessonId) {}

    /**
     * Считает векторы, если считать есть чем.
     *
     * Без модели эмбеддингов не делает ничего: задание отработало бы вхолостую,
     * а очередь копила бы такие десятками. Со стороны это неотличимо от
     * медленной работы — заданий много, «векторы всё считаются», — хотя считать
     * нечего и не начинали. Включив смысловой поиск позже, векторы для
     * накопленного материала получают командой `lms:reindex`.
     *
     * Из интерфейса — сразу, в том же запросе. Так «сохранил — значит нашлось»
     * выполняется само, без работника очереди, о котором надо помнить: пока его
     * забывали запустить, ни один урок не получал векторов вовсе. Стоит это
     * одного обращения к сервису на сохранение — доли секунды, и автор всё
     * равно ждёт ответа на нажатие.
     *
     * Из консоли — в очередь: сидер и импорт создают уроки сотнями, и вызов
     * сервиса на каждый превратил бы их в часы.
     *
     * Не `queue()`: этим именем диспетчер Laravel зовёт метод самого задания,
     * когда кладёт его в соединение, и статический перекрыл бы его.
     */
    public static function dispatchIfConfigured(int $lessonId): void
    {
        if (! app(Embedder::class)->isAvailable()) {
            return;
        }

        try {
            app()->runningInConsole()
                ? self::dispatch($lessonId)
                : self::dispatchSync($lessonId);
        } catch (Throwable $exception) {
            // Сохранение уже состоялось и отменяться не должно: сервис
            // эмбеддингов посторонний, а текст — авторский.
            //
            // Ловим на обоих путях: на настоящей очереди задание тут не
            // выполняется и бросить ничего не может, а вот при
            // QUEUE_CONNECTION=sync — выполняется, и без этого отказ сервиса
            // ронял бы сохранение.
            Log::warning('Векторы при сохранении не посчитаны.', [
                'lesson' => $lessonId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Урок сохраняют подряд по нескольку раз. Считать векторы параллельно для
     * одного и того же урока незачем: лишний расход и гонка за одну и ту же
     * строку.
     *
     * Замок снимается сам через пять минут — на случай, если процесс оборвался,
     * не дойдя до его освобождения: иначе урок молча перестал бы индексироваться.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->lessonId))->expireAfter(300)];
    }

    public function handle(EmbedTranscriptSegments $segments, EmbedLessonAnswers $answers): void
    {
        $segments->handle($this->lessonId);
        $answers->handle($this->lessonId);
    }
}
