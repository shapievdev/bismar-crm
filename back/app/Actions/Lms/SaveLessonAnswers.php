<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AnswerSource;
use App\Jobs\EmbedLesson;
use App\Models\Lesson;
use App\Models\LessonAnswer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Записывает таблицу урока целиком.
 *
 * Редактор всегда присылает её всю, поэтому здесь замена, а не сверка. Но не
 * «удалить и вставить заново»: у строки есть посчитанные векторы, и переписав
 * её новой записью, мы бы выбросили их у каждой строки при любой правке — даже
 * у тех, которых правка не касалась. Поэтому строки сопоставляются по месту, а
 * векторы сбрасываются только там, где изменился текст.
 */
final readonly class SaveLessonAnswers
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, LessonAnswer>
     */
    public function handle(Lesson $lesson, array $rows): Collection
    {
        DB::transaction(function () use ($lesson, $rows): void {
            $existing = $lesson->answers()->get()->keyBy('position');

            foreach (array_values($rows) as $position => $row) {
                $attributes = $this->attributes($row, $position);
                $current = $existing->get($position);

                if ($current === null) {
                    $lesson->answers()->create($attributes);

                    continue;
                }

                // Векторы описывают текст, а не строку: пока вопрос и ответ те
                // же, пересчитывать нечего. Сбрасывать их на каждое сохранение
                // значило бы гонять сервис эмбеддингов из-за исправленной
                // опечатки в номере страницы.
                if ($current->question !== $attributes['question'] || $current->answer !== $attributes['answer']) {
                    $attributes['question_embedding'] = null;
                    $attributes['answer_embedding'] = null;
                    $attributes['embedding_model'] = null;
                }

                $current->forceFill($attributes)->save();
            }

            // Строки, которых в присланной таблице не осталось.
            $lesson->answers()->where('position', '>=', count($rows))->delete();
        });

        EmbedLesson::dispatchIfConfigured((int) $lesson->getKey());

        return $lesson->loadAnswers()->answers;
    }

    /**
     * Поля места, не относящиеся к выбранному виду, обнуляются.
     *
     * Строка с таймкодом и номером страницы разом не значит ничего, а пережить
     * смену вида источника они могут запросто: автор переключил «видео» на
     * «файл», и в записи остался таймкод от прошлого выбора.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row, int $position): array
    {
        $kind = AnswerSource::from((string) $row['source_kind']);

        return [
            'position' => $position,
            'question' => trim((string) $row['question']),
            'answer' => trim((string) $row['answer']),
            'source_kind' => $kind,
            'source_attachment_id' => $kind === AnswerSource::Attachment
                ? ($row['source_attachment_id'] ?? null)
                : null,
            'source_seconds' => $kind === AnswerSource::Video
                ? ($row['source_seconds'] ?? null)
                : null,
            'source_page' => $kind === AnswerSource::Attachment
                ? ($row['source_page'] ?? null)
                : null,
            'source_block_id' => $kind === AnswerSource::Text
                ? ($row['source_block_id'] ?? null)
                : null,
        ];
    }
}
