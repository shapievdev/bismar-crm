<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\LessonTranscript;
use App\Support\Lms\TranscriptCue;

/**
 * Записывает куски расшифровки — то, по чему ищет консультант.
 *
 * Полная замена, а не сверка: расшифровку загружают целиком, и вычислять, какие
 * куски уцелели, дороже, чем переписать их все.
 */
final readonly class SaveTranscriptSegments
{
    /**
     * @param  list<TranscriptCue>  $cues
     * @return int сколько кусков записано
     */
    public function handle(LessonTranscript $transcript, array $cues): int
    {
        $transcript->segments()->delete();

        if ($cues === []) {
            return 0;
        }

        $heading = $this->heading($transcript);

        $transcript->segments()->insert(array_map(
            static fn (int $position, TranscriptCue $cue): array => [
                'transcript_id' => $transcript->getKey(),
                // Копией из расшифровки, чтобы поиск не соединял три таблицы
                // ради того, чей это урок.
                'lesson_id' => $transcript->lesson_id,
                'position' => $position,
                'heading' => $heading,
                'content' => $cue->text,
                'starts_at_seconds' => $cue->startsAt,
                'page' => $cue->page,
                'source_block_id' => $cue->blockId,
            ],
            array_keys($cues),
            $cues,
        ));

        return count($cues);
    }

    /**
     * Заголовок, весящий в поиске больше тела.
     *
     * Название урока, а при расшифровке файла — вместе с именем файла: вопрос
     * нередко называет документ, а не то, что в нём написано («что там в
     * СНиПе про влажные помещения»).
     */
    private function heading(LessonTranscript $transcript): string
    {
        $lessonTitle = (string) $transcript->lesson?->title;
        $attachment = $transcript->attachment?->name;

        return $attachment === null ? $lessonTitle : $lessonTitle.' — '.$attachment;
    }
}
