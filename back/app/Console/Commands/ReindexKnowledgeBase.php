<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ai\EmbedLessonAnswers;
use App\Actions\Ai\EmbedTranscriptSegments;
use App\Actions\Lms\SyncLessonTranscripts;
use App\Models\Lesson;
use App\Support\Lms\BlockIdentifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Пересобирает всё, по чему ищет консультант.
 *
 * Нужна после первого разворачивания, после смены модели эмбеддингов — старые
 * векторы с новыми несравнимы — и всякий раз, когда материал приезжает в базу
 * мимо приложения: импортом или прямым SQL, где наблюдатель не срабатывает.
 *
 * Загруженные расшифровки не трогает: они не производное от текста, и
 * пересобрать их не из чего.
 */
final class ReindexKnowledgeBase extends Command
{
    protected $signature = 'lms:reindex {--fresh : пересчитать векторы заново, даже если они на вид свежие}';

    protected $description = 'Пересобрать расшифровки и векторы, по которым ищет консультант';

    public function handle(SyncLessonTranscripts $sync, BlockIdentifier $blocks): int
    {
        $lessons = 0;
        $derived = 0;
        $named = 0;

        $bar = $this->output->createProgressBar(Lesson::query()->count());

        Lesson::query()->chunkById(100, function ($chunk) use ($sync, $blocks, &$lessons, &$derived, &$named, $bar): void {
            foreach ($chunk as $lesson) {
                $named += $this->nameBlocks($lesson, $blocks) ? 1 : 0;
                $derived += $sync->handle($lesson);
                $lessons++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Уроков: %d. Расшифровок выведено из статей: %d.', $lessons, $derived));

        if ($named > 0) {
            $this->info(sprintf('Статей, получивших имена блоков: %d.', $named));
        }

        $this->embed();

        return self::SUCCESS;
    }

    /**
     * Проставляет имена блокам статьи, если их ещё нет.
     *
     * Уроки, написанные до появления таблиц, адресовать нечем: ни строка, ни
     * расшифровка не могут сослаться на абзац, у которого нет имени. Само
     * сохранение их бы вылечило, но ждать, пока автор откроет каждый старый
     * урок, — значит не вылечить их никогда.
     *
     * Сохраняется тихо, мимо наблюдателя: текст не менялся, и пересобирать
     * из-за этого расшифровки незачем — их и так пересобирает вызывающий.
     */
    private function nameBlocks(Lesson $lesson, BlockIdentifier $blocks): bool
    {
        $document = $blocks->assign($lesson->content_json);

        if ($document === $lesson->content_json) {
            return false;
        }

        $lesson->forceFill(['content_json' => $document])->saveQuietly();

        return true;
    }

    /**
     * Векторы считаются следом, но их отсутствие не делает переиндексацию
     * неудачной: без них поиск остаётся словесным и работает.
     *
     * Обычно пересчитываются только устаревшие — те, у которых вектора нет или
     * он от другой модели. Свежесть при этом определяется по модели, а текст,
     * по которому считают, задан кодом: меняется он — и вектор, на вид
     * актуальный, оказывается посчитанным по-старому. На такой случай --fresh.
     */
    private function embed(): void
    {
        $fresh = (bool) $this->option('fresh');

        try {
            $embedded = app(EmbedTranscriptSegments::class)->handle(force: $fresh)
                + app(EmbedLessonAnswers::class)->handle(force: $fresh);
        } catch (Throwable $exception) {
            $this->warn('Векторы не посчитаны: '.$exception->getMessage());
            $this->line('Поиск будет работать по словам. Проверьте модель эмбеддингов в настройках.');

            return;
        }

        $this->info($embedded === 0
            ? 'Векторы не считались: модель эмбеддингов не задана либо они уже актуальны.'
            : sprintf('Векторов посчитано: %d.', $embedded));
    }
}
