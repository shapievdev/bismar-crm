<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Чего материал ещё ждёт от человека, прежде чем зачесться.
 *
 * Требований у материала два, и оба необязательны сами по себе: приложенный тест
 * и обязательный опрос. Сложить их можно было бы по месту — в зачёте урока, в
 * ознакомлении с документом, в ознакомлении с новостью, в оценке попытки, — и
 * ровно это однажды разошлось бы: одно место требовало бы опрос, другое нет, и
 * человек видел бы документ то прочитанным, то нет, смотря каким путём он туда
 * пришёл.
 *
 * Поэтому правило одно и живёт здесь, а ставит отметку — CreditMaterial.
 *
 * Необязательный опрос не держит ничего и в расчёт не идёт вовсе: его на то и
 * заводят, чтобы спросить мнение у тех, кому есть что сказать.
 */
final readonly class MaterialDues
{
    /**
     * Всё ли, что материал требует, этот человек сдал.
     *
     * Про очередь плана обучения здесь не спрашивается: она решает, дошёл ли
     * человек до урока, а не сдал ли он то, что урок требует, — см. CompleteLesson.
     */
    public function settled(Model $owner, User $reader): bool
    {
        return ! $this->surveyPending($owner, $reader) && $this->quizSettled($owner, $reader);
    }

    /**
     * Есть ли обязательный опрос, который человек ещё не проходил.
     *
     * Закрытый по сроку опрос не держит материал: срок вышел не по вине
     * читателя, и запирать за ним урок значило бы запереть его навсегда.
     */
    public function surveyPending(Model $owner, User $reader): bool
    {
        $survey = $this->surveyOf($owner);

        return $survey !== null
            && $survey->is_required
            && $survey->isOpen()
            && ! $survey->isAnsweredBy($reader);
    }

    /**
     * Сдан ли приложенный тест — или его нет вовсе.
     *
     * У новости своя стопка таблиц под проверку (NewsQuiz), у остальных общая
     * (Quiz), и это единственное место, где разница видна.
     */
    public function quizSettled(Model $owner, User $reader): bool
    {
        if ($owner instanceof News) {
            $quiz = $owner->loadMissing('quiz')->quiz;

            return $quiz === null || $quiz->attempts()
                ->where('user_id', $reader->getKey())
                ->where('passed', true)
                ->exists();
        }

        if ($owner instanceof Lesson || $owner instanceof Regulation || $owner instanceof RegulationVersion) {
            $quiz = $owner->loadMissing('quiz')->quiz;

            return $quiz === null || $quiz->attempts()
                ->where('user_id', $reader->getKey())
                ->where('passed', true)
                ->exists();
        }

        return true;
    }

    /**
     * Почему материал пока не зачитывается — словами, которые увидит человек.
     *
     * Про опрос, а не про тест: про тест ему скажут там, где он его сдаёт, а
     * сюда доходит именно тот случай, когда всё сдано, кроме мнения.
     */
    public function pendingMessage(Model $owner): string
    {
        $survey = $this->surveyOf($owner);
        $title = $survey?->title;

        return $title === null || $title === ''
            ? 'Сначала пройдите опрос — он обязательный.'
            : sprintf('Сначала пройдите опрос «%s» — он обязательный.', $title);
    }

    /**
     * Опрос материала, если он есть.
     *
     * Через `loadMissing`, а не запросом: у материала опрос один, и на странице
     * он уже загружен — второй запрос за тем же ничего не добавит.
     */
    public function surveyOf(Model $owner): ?Survey
    {
        if ($owner instanceof Lesson
            || $owner instanceof Regulation
            || $owner instanceof RegulationVersion
            || $owner instanceof News) {
            return $owner->loadMissing('survey')->survey;
        }

        return null;
    }
}
