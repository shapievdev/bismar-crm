<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\User;
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Support\Collection;

/**
 * Когда шаг плана обучения считается пройденным — одно правило на приложение.
 *
 * Правил на самом деле два, по числу видов шага: курс пройден, когда закрыты
 * все его уроки, документ — когда с ним ознакомились. Но у документа бывает
 * проверка, и тогда одной отметки мало: «ознакомлен» ставят нажатием, и без
 * сданного теста шаг открывал бы следующий тому, кто ничего не читал.
 *
 * Класс отвечает только на этот вопрос и ничего не решает про доступ — очередь
 * шагов строит поверх него LearningPlan. Отдельно он потому, что ответ нужен
 * двоим: экрану плана, который рисует галочки, и запрету, который по этим же
 * галочкам открывает курсы. Разойдись они хоть на день — сотрудник увидел бы
 * пройденный план и закрытый каталог.
 *
 * Считается пачкой на весь план: шагов бывает десяток, и вопрос «сдан ли тест»
 * на каждом из них — это десяток запросов там, где хватает трёх.
 */
final class StepCompletion
{
    /**
     * Когда каждый из шагов был пройден.
     *
     * Ключ есть у всякого шага, а не только у пройденного: `null` — честный
     * ответ «не пройден», и вызывающему не приходится гадать, шаг это без
     * отметки или шаг, о котором забыли спросить.
     *
     * @param  Collection<int, LearningPlanItem>  $steps
     * @return array<int, ?Carbon> номер шага => когда пройден
     */
    public function of(User $learner, Collection $steps): array
    {
        $courses = $this->finishedCourses($learner, $this->idsOf($steps, Course::class));
        $documents = $this->readDocuments($learner, $this->idsOf($steps, Regulation::class));

        return $steps
            ->mapWithKeys(fn (LearningPlanItem $step): array => [
                (int) $step->getKey() => $step->plannable instanceof Regulation
                    ? ($documents[(int) $step->plannable_id] ?? null)
                    : ($courses[(int) $step->plannable_id] ?? null),
            ])
            ->all();
    }

    /**
     * Курсы, закрытые этим человеком целиком.
     *
     * Дата берётся из записи на курс, а не считается заново: её проставили в
     * тот день, когда был пройден последний урок, и пересчёт по нынешнему
     * составу уроков передвинул бы её на сегодня.
     *
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    private function finishedCourses(User $learner, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Enrollment::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('course_id', $ids)
            ->whereNotNull('completed_at')
            ->pluck('completed_at', 'course_id')
            ->map(fn (mixed $at): Carbon => Carbon::parse((string) $at))
            ->all();
    }

    /**
     * Документы, с которыми этот человек и ознакомился, и — если было чем —
     * отчитался.
     *
     * Днём прохождения остаётся отметка об ознакомлении, а не день сдачи
     * теста: читатель считает пройденным то место, где нажал «ознакомлен», и
     * сданный позже тест эту дату не переписывает.
     *
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    private function readDocuments(User $learner, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $acknowledged = RegulationAcknowledgement::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('regulation_id', $ids)
            ->pluck('acknowledged_at', 'regulation_id')
            ->map(fn (mixed $at): Carbon => Carbon::parse((string) $at))
            ->all();

        foreach ($this->documentsAwaitingQuiz($learner, array_keys($acknowledged)) as $document) {
            unset($acknowledged[$document]);
        }

        return $acknowledged;
    }

    /**
     * Документы, проверку при которых этот человек ещё не сдал.
     *
     * Обычно спорить не о чем: у документа с проверкой кнопки «ознакомлен» нет
     * вовсе, и отметку ставит сама сдача (GradeQuizAttempt, ReviewAttestation).
     * Условие здесь — про день, когда проверку приложили к документу, который
     * уже прочитан: старая отметка не должна открывать план тому, кто нового
     * теста не видел.
     *
     * Проверка без вопросов не считается: пустой тест не сдать ничем, и шаг
     * при нём закрывал бы план навсегда. Работа, отправленная на аттестацию,
     * проверку тоже не проходит, пока её не приняли: `passed` у неё пуст до
     * вердикта, и это не «сдал», а «ждёт».
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function documentsAwaitingQuiz(User $learner, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, int> $quizzes номер проверки => номер документа */
        $quizzes = Quiz::query()
            ->where('quizzable_type', (new Regulation)->getMorphClass())
            ->whereIn('quizzable_id', $ids)
            ->has('questions')
            ->pluck('quizzable_id', 'id')
            ->map(intval(...));

        if ($quizzes->isEmpty()) {
            return [];
        }

        $passed = QuizAttempt::query()
            ->whereIn('quiz_id', $quizzes->keys())
            ->where('user_id', $learner->getKey())
            ->where('passed', true)
            ->pluck('quiz_id')
            ->map(intval(...))
            ->all();

        return $quizzes->except($passed)->values()->all();
    }

    /**
     * Номера того, что назначено, — по видам.
     *
     * @param  Collection<int, LearningPlanItem>  $steps
     * @param  class-string  $model
     * @return list<int>
     */
    private function idsOf(Collection $steps, string $model): array
    {
        $type = (new $model)->getMorphClass();

        return $steps
            ->where('plannable_type', $type)
            ->pluck('plannable_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
    }
}
