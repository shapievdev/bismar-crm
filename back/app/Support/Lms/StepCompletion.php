<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\User;
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Support\Collection;

/**
 * Когда материал считается изученным — одно правило на приложение.
 *
 * Правил два, по числу видов: курс пройден, когда закрыты все его уроки,
 * документ — когда с ним ознакомились. Но у документа бывает проверка, и тогда
 * одной отметки мало: «ознакомлен» ставят нажатием, и без сданного теста
 * материал считался бы изученным тем, кто ничего не читал.
 *
 * Спрашивает об этом маршрут адаптации (StageRequirements): требование
 * «изучите материал» закрывается ровно этим правилом. Родился класс при плане
 * обучения — план ушёл (2026-10-25), правило осталось.
 *
 * Считается пачкой на весь маршрут: материалов бывает десяток, и вопрос «сдан
 * ли тест» на каждом — это десяток запросов там, где хватает трёх.
 */
final class StepCompletion
{
    /**
     * Когда изучен каждый материал из списка пар «morph-вид + номер».
     *
     * @param  list<array{type: string, id: int}>  $materials
     * @return array<string, ?Carbon> «type:id» => когда изучен
     */
    public function ofMaterials(User $learner, array $materials): array
    {
        $courseMorph = (new Course)->getMorphClass();
        $regulationMorph = (new Regulation)->getMorphClass();

        $courseIds = [];
        $regulationIds = [];

        foreach ($materials as $material) {
            if ($material['type'] === $courseMorph) {
                $courseIds[] = $material['id'];
            } elseif ($material['type'] === $regulationMorph) {
                $regulationIds[] = $material['id'];
            }
        }

        $courses = $this->finishedCourses($learner, $courseIds);
        $documents = $this->readDocuments($learner, $regulationIds);

        $done = [];

        foreach ($materials as $material) {
            $done[$material['type'].':'.$material['id']] = $material['type'] === $courseMorph
                ? ($courses[$material['id']] ?? null)
                : ($documents[$material['id']] ?? null);
        }

        return $done;
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
}
