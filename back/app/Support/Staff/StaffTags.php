<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\TenureTag;
use Illuminate\Support\Facades\DB;

/**
 * Какие теги висят на человеке — и почему они не хранятся.
 *
 * Тег по стажу выводится из даты приёма, а не лежит в базе: хранимый живёт до
 * следующего ночного пересчёта и ровно столько же врёт. Вычисленный не
 * устаревает никогда.
 *
 * Стажёрство — единственный тег с двумя условиями: тридцать дней **или**
 * несданная аттестация испытательного срока. Второе считается не по человеку, а
 * по тому, что ему назначено, и потому живёт здесь, а не в самом перечислении.
 *
 * **Назначенной** аттестация считается тогда, когда человек до неё дотянулся:
 * записан на курс, в котором она стоит, или материал с ней лежит у него в плане
 * обучения. Без этого условия обошлось бы дешевле, но стажёрами навсегда стали
 * бы все, кому аттестацию не назначали вовсе, — а это вся компания в тот день,
 * когда отметку «аттестация испытательного срока» поставят первой проверке.
 */
final readonly class StaffTags
{
    /**
     * Кто ещё не сдал назначенную ему аттестацию испытательного срока.
     *
     * Одним запросом на весь отчёт, а не по человеку: список людей в отчёте —
     * это вся компания, и три запроса на каждого означали бы полтысячи
     * обращений ради одной колонки.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function stillOnProbation(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            with probation as (
                select q.id, q.quizzable_type, q.quizzable_id
                from quizzes q
                where q.is_probation
            ),
            -- Аттестация урока достаётся тому, кто записан на её курс.
            by_course as (
                select e.user_id, p.id as quiz_id
                from probation p
                join lessons l on p.quizzable_type = 'lesson' and l.id = p.quizzable_id
                join course_modules m on m.id = l.module_id
                join enrollments e on e.course_id = m.course_id
                where e.user_id = any(?)
            ),
            -- И тому, кому этот курс назначен планом: план — тоже назначение.
            by_plan_course as (
                select i.user_id, p.id as quiz_id
                from probation p
                join lessons l on p.quizzable_type = 'lesson' and l.id = p.quizzable_id
                join course_modules m on m.id = l.module_id
                join learning_plan_items i
                    on i.plannable_type = 'course' and i.plannable_id = m.course_id
                where i.user_id = any(?)
            ),
            -- Аттестация документа и его версии — тому, кому назначен документ.
            by_plan_document as (
                select i.user_id, p.id as quiz_id
                from probation p
                left join regulation_versions v
                    on p.quizzable_type = 'regulation_version' and v.id = p.quizzable_id
                join learning_plan_items i
                    on i.plannable_type = 'regulation'
                    and i.plannable_id = coalesce(v.regulation_id, p.quizzable_id)
                where p.quizzable_type in ('regulation', 'regulation_version')
                  and i.user_id = any(?)
            ),
            assigned as (
                select * from by_course
                union
                select * from by_plan_course
                union
                select * from by_plan_document
            )
            select distinct a.user_id
            from assigned a
            where not exists (
                select 1 from quiz_attempts t
                where t.quiz_id = a.quiz_id and t.user_id = a.user_id and t.passed
            )
            SQL, [
            '{'.implode(',', $userIds).'}',
            '{'.implode(',', $userIds).'}',
            '{'.implode(',', $userIds).'}',
        ]);

        return array_map(static fn (object $row): int => (int) $row->user_id, $rows);
    }

    /**
     * Тег по стажу для одного человека.
     *
     * @param  int|null  $days  сколько отработал; null — дата приёма не заполнена
     * @param  bool  $onProbation  висит ли на нём несданная аттестация
     */
    public function tenureTag(?int $days, bool $onProbation): ?TenureTag
    {
        if ($days === null) {
            // Без даты приёма стажа нет, и придумывать его нельзя: «стажёр» у
            // человека с десятью годами за спиной хуже, чем пустое место.
            return null;
        }

        return $onProbation ? TenureTag::Trainee : TenureTag::byDays($days);
    }
}
