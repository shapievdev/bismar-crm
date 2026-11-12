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
 * Стажёрство — единственный тег с тремя условиями: тридцать дней, **или**
 * непустой незавершённый маршрут адаптации, **или** несданная аттестация
 * испытательного срока. Последние два считаются не по человеку, а по тому,
 * что ему положили, и потому живут здесь, а не в самом перечислении.
 *
 * **Назначенной** аттестация считается тогда, когда человек до неё дотянулся:
 * записан на курс, в котором она стоит. Без этого условия обошлось бы дешевле,
 * но стажёрами навсегда стали бы все, кому аттестацию не назначали вовсе, — а
 * это вся компания в тот день, когда отметку «аттестация испытательного
 * срока» поставят первой проверке. (Прежде сюда входило и назначение планом
 * обучения — план уступил место маршруту адаптации, 2026-10-25.)
 */
final readonly class StaffTags
{
    /**
     * Кто ещё числится стажёром по назначению: идёт маршрут адаптации или не
     * сдана назначенная аттестация испытательного срока.
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
            awaiting_probation as (
                select distinct a.user_id
                from by_course a
                where not exists (
                    select 1 from quiz_attempts t
                    where t.quiz_id = a.quiz_id and t.user_id = a.user_id and t.passed
                )
            ),
            /*
             * Непустой незавершённый маршрут — человек ещё проходит адаптацию,
             * каким бы ни был стаж. Пустая карта не в счёт: она заведена у
             * каждого сотрудника, и по ней стажёрами стала бы вся компания.
             */
            on_route as (
                select distinct j.user_id
                from onboarding_journeys j
                where j.status in ('active', 'paused')
                  and j.user_id = any(?)
                  and exists (
                      select 1 from onboarding_journey_stages s
                      where s.journey_id = j.id
                  )
            )
            select user_id from awaiting_probation
            union
            select user_id from on_route
            SQL, [
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
