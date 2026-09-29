<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\MaterialVersion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Переключатель версий на странице материала.
 *
 * Одно на документ и на урок: список версий приходит названиями, тело — только
 * у открытой, а какую открыть, человек может попросить прямо (`?version=`).
 * Собирать это дважды значило бы однажды показать рознице чужой текст в одном
 * из двух разделов.
 */
trait ShowsMaterialVersions
{
    /**
     * @param  Lesson|Regulation  $material  материал, у которого бывают версии
     * @param  string|null  $asked  номер версии из запроса; пустая строка —
     *                              «открой общую, а не мою»
     * @param  bool  $forEditor  ведёт ли этот человек материал: круг групп
     *                           показывают только ему
     */
    protected function attachVersions(Model $material, User $reader, ?string $asked, bool $forEditor): void
    {
        $available = $this->versions->visibleTo($material, $reader);
        $mine = $this->versions->mineAmong($available, $reader);

        foreach ($available as $version) {
            $version->setAttribute('is_mine', $mine?->getKey() === $version->getKey());

            // Для кого версия написана — только тому, кто материал ведёт.
            // Читателю список групп ни о чём не говорит: ему важно, какая
            // версия его.
            if (! $forEditor) {
                $version->unsetRelation('groups');
                $version->unsetRelation('departments');
            }
        }

        $material->setAttribute('available_versions', $available);

        // Общую просят пустой строкой: «открой мне не мою версию, а исходную».
        // Отличить это от «не просили ничего» иначе нечем.
        //
        // Строка берётся из того же списка, а не спрашивается заново: на ней
        // уже стоит признак «моя», и двойник ушёл бы на экран без него.
        $shown = $asked === null ? $mine : $available->firstWhere('id', (int) $asked);

        if ($shown === null) {
            return;
        }

        $shown->load([
            'quiz.questions.options',
            'quiz.examiner:id,last_name,first_name,middle_name',
            'survey.questions.options',
            // Файлы версии лежат в своей таблице у урока и у документа.
            $shown->isOfLesson() ? 'lessonAttachments' : 'regulationAttachments',
        ]);

        $shown->setAttribute('sends_content', true);
        $shown->setAttribute('own_attempts', $this->attemptsOfVersion($shown->quiz, $reader));

        $material->setAttribute('shown_version', $shown);
    }

    /**
     * Прошлые попытки этого человека по проверке версии — те же, что показывает
     * материал без версий.
     *
     * @return list<array<string, mixed>>
     */
    private function attemptsOfVersion(?Quiz $quiz, User $reader): array
    {
        if ($quiz === null) {
            return [];
        }

        return $quiz->attempts()
            ->where('user_id', $reader->getKey())
            ->with('reviewer:id,last_name,first_name,middle_name')
            ->latest('completed_at')
            ->limit(10)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->getKey(),
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'review_status' => $attempt->review_status->value,
                'review_status_label' => $attempt->review_status->label(),
                'review_comment' => $attempt->review_comment,
                'reviewed_at' => $attempt->reviewed_at?->toIso8601String(),
                'reviewed_by' => $attempt->reviewer?->name,
            ])->all();
    }

    /** Версия, открытая человеку на этой странице. Null — общая. */
    protected function shownVersion(Model $material): ?MaterialVersion
    {
        $shown = $material->getAttribute('shown_version');

        return $shown instanceof MaterialVersion ? $shown : null;
    }
}
