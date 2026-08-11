<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AnswerSource;
use App\Jobs\EmbedLesson;
use App\Models\Lesson;
use App\Models\LessonTranscript;
use App\Support\Lms\TranscriptParser;
use Illuminate\Support\Facades\DB;

/**
 * Записывает расшифровку одной единицы содержания урока.
 *
 * Одна на источник: загруженная повторно заменяет прежнюю, а не ложится рядом.
 * Сам текст расшифровки в базе не хранится целиком — он и есть свои куски,
 * и держать вдобавок исходную простыню значило бы хранить одно и то же дважды.
 */
final readonly class SaveLessonTranscript
{
    public function __construct(
        private TranscriptParser $parser,
        private SaveTranscriptSegments $segments,
    ) {}

    public function handle(
        Lesson $lesson,
        AnswerSource $kind,
        string $raw,
        ?int $attachmentId = null,
        ?string $blockId = null,
        ?string $originalName = null,
    ): LessonTranscript {
        $transcript = DB::transaction(function () use ($lesson, $kind, $raw, $attachmentId, $blockId, $originalName): LessonTranscript {
            $transcript = LessonTranscript::query()->updateOrCreate(
                [
                    'lesson_id' => $lesson->getKey(),
                    'source_kind' => $kind,
                    'source_attachment_id' => $attachmentId,
                    'source_block_id' => $blockId,
                ],
                [
                    // Исходник, а не только его куски: куски — производное, и
                    // собрать из них обратно то, что вставил автор, нельзя.
                    // Править он будет именно это.
                    'content' => $raw,

                    // Загруженная, а не выведенная: правка текста урока её
                    // больше не тронет.
                    'is_derived' => false,
                    'original_name' => $originalName,
                    'format' => $this->parser->format($raw),
                ],
            );

            $this->segments->handle($transcript->load('lesson', 'attachment'), $this->parser->parse($raw));

            return $transcript;
        });

        EmbedLesson::dispatchIfConfigured((int) $lesson->getKey());

        return $transcript->load('segments');
    }

    /**
     * Убирает загруженную расшифровку.
     *
     * У блока статьи на её место возвращается выведенная из собственного
     * текста — иначе снятие расшифровки делало бы абзац невидимым для поиска,
     * чего автор, снимая её, не имел в виду.
     */
    public function remove(LessonTranscript $transcript, SyncLessonTranscripts $sync): void
    {
        $lesson = $transcript->lesson;
        $wasText = $transcript->source_kind === AnswerSource::Text;

        $transcript->delete();

        if ($lesson !== null && $wasText) {
            $sync->handle($lesson);
        }

        if ($lesson !== null) {
            EmbedLesson::dispatchIfConfigured((int) $lesson->getKey());
        }
    }
}
