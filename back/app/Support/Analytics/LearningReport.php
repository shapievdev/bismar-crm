<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\CourseStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика обучения: сколько материала собрано и как его проходят.
 *
 * Читает свою же базу, а не ClickHouse: витрина продаж об уроках ничего не
 * знает, и цифры здесь считаются по тем самым строкам, которые пишет
 * приложение.
 *
 * Уволенные не участвуют нигде. Иначе прогресс компании падал бы всякий раз,
 * когда человек уходит, не догуляв курс до конца, — и отчёт говорил бы о тех,
 * кого уже не спросишь.
 *
 * Прогресс записи — доля пройденных уроков курса. Считается в базе, а не
 * ProgressCalculator'ом: тот отвечает на вопрос об одном человеке, а здесь
 * нужен средний по тысяче записей, и тысяча запросов ради одного числа — не
 * отчёт, а способ положить сервер.
 */
final class LearningReport
{
    /** Сколько строк показывают рейтинги: дальше идёт длинный хвост. */
    private const TOP = 15;

    /**
     * Общая сводка — числа, ради которых экран и открывают.
     *
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $staff = User::query()->employed()->count();

        $material = DB::selectOne(<<<'SQL'
            select
                (select count(*) from courses where deleted_at is null) as courses,
                (select count(*) from courses where deleted_at is null and status = ?) as published_courses,
                (select count(*) from regulations where deleted_at is null) as regulations,
                (select count(*) from regulations where deleted_at is null and status = ?) as published_regulations,
                (
                    select count(l.id)
                    from lessons l
                    join course_modules m on m.id = l.module_id
                    join courses c on c.id = m.course_id and c.deleted_at is null
                ) as lessons
            SQL, [CourseStatus::Published->value, CourseStatus::Published->value]);

        $learning = DB::selectOne($this->progressSql().<<<'SQL'
            select
                count(*) as enrollments,
                count(distinct user_id) as learners,
                count(*) filter (where completed_at is not null) as completed,
                coalesce(round(avg(progress)), 0) as average_progress
            from progress
            SQL);

        $quizzes = DB::selectOne(<<<'SQL'
            select
                count(*) as attempts,
                count(*) filter (where a.passed) as passed,
                coalesce(round(avg(a.score)), 0) as average_score
            from quiz_attempts a
            join users u on u.id = a.user_id and u.dismissed_at is null
            SQL);

        $acknowledgements = DB::selectOne(<<<'SQL'
            select
                count(*) as total,
                count(distinct a.user_id) as people
            from regulation_acknowledgements a
            join users u on u.id = a.user_id and u.dismissed_at is null
            join regulations r on r.id = a.regulation_id and r.deleted_at is null
            SQL);

        $plans = DB::selectOne(<<<'SQL'
            select
                count(*) as steps,
                count(distinct user_id) as people,
                count(*) filter (where done) as done
            from (
                select
                    i.user_id,
                    case i.plannable_type
                        when 'course' then exists (
                            select 1 from enrollments e
                            where e.user_id = i.user_id
                              and e.course_id = i.plannable_id
                              and e.completed_at is not null
                        )
                        when 'regulation' then exists (
                            select 1 from regulation_acknowledgements a
                            where a.user_id = i.user_id and a.regulation_id = i.plannable_id
                        )
                        else false
                    end as done
                from learning_plan_items i
                join users u on u.id = i.user_id and u.dismissed_at is null
            ) steps
            SQL);

        return [
            'staff' => $staff,

            'courses' => (int) $material->courses,
            'published_courses' => (int) $material->published_courses,
            'regulations' => (int) $material->regulations,
            'published_regulations' => (int) $material->published_regulations,
            'lessons' => (int) $material->lessons,

            'enrollments' => (int) $learning->enrollments,
            'learners' => (int) $learning->learners,
            'completed' => (int) $learning->completed,
            'average_progress' => (int) $learning->average_progress,

            'quiz_attempts' => (int) $quizzes->attempts,
            'quiz_passed' => (int) $quizzes->passed,
            'quiz_average_score' => (int) $quizzes->average_score,

            'acknowledgements' => (int) $acknowledgements->total,
            'acknowledged_by' => (int) $acknowledgements->people,

            'plan_people' => (int) $plans->people,
            'plan_steps' => (int) $plans->steps,
            'plan_done' => (int) $plans->done,
        ];
    }

    /**
     * Курсы: сколько записалось, сколько дошло до конца и как далеко ушли
     * остальные.
     *
     * @return list<array<string, mixed>>
     */
    public function courses(): array
    {
        $rows = DB::select($this->progressSql().sprintf(<<<'SQL'
            select
                c.id,
                c.title,
                c.slug,
                c.status,
                coalesce(lessons.lessons, 0) as lessons,
                count(p.id) as enrolled,
                count(p.id) filter (where p.completed_at is not null) as completed,
                coalesce(round(avg(p.progress)), 0) as average_progress
            from courses c
            left join lesson_counts lessons on lessons.course_id = c.id
            left join progress p on p.course_id = c.id
            where c.deleted_at is null
            group by c.id, c.title, c.slug, c.status, lessons.lessons
            order by enrolled desc, count(p.id) filter (where p.completed_at is not null) desc, c.title collate "und-x-icu"
            limit %d
            SQL, self::TOP));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'is_published' => $row->status === CourseStatus::Published->value,
            'lessons' => (int) $row->lessons,
            'enrolled' => (int) $row->enrolled,
            'completed' => (int) $row->completed,
            'average_progress' => (int) $row->average_progress,
        ], $rows);
    }

    /**
     * Регламенты: сколько человек с ними ознакомилось.
     *
     * @return list<array<string, mixed>>
     */
    public function regulations(): array
    {
        $rows = DB::select(sprintf(<<<'SQL'
            select
                r.id,
                r.title,
                r.slug,
                r.status,
                count(a.id) filter (where u.dismissed_at is null) as acknowledged
            from regulations r
            left join regulation_acknowledgements a on a.regulation_id = r.id
            left join users u on u.id = a.user_id
            where r.deleted_at is null
            group by r.id, r.title, r.slug, r.status
            order by acknowledged desc, r.title collate "und-x-icu"
            limit %d
            SQL, self::TOP));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'is_published' => $row->status === CourseStatus::Published->value,
            'acknowledged' => (int) $row->acknowledged,
        ], $rows);
    }

    /**
     * Записи на курсы с посчитанным прогрессом — общая заготовка для сводки и
     * для рейтинга курсов.
     *
     * Уроков в курсе может не быть вовсе: свежесобранный курс — это ноль
     * процентов, а не деление на ноль.
     */
    private function progressSql(): string
    {
        return <<<'SQL'
            with lesson_counts as (
                select m.course_id, count(l.id) as lessons
                from course_modules m
                left join lessons l on l.module_id = m.id
                group by m.course_id
            ),
            progress as (
                select
                    e.id,
                    e.course_id,
                    e.user_id,
                    e.completed_at,
                    case
                        when coalesce(lessons.lessons, 0) = 0 then 0
                        else least(100, round(count(done.id) * 100.0 / lessons.lessons))
                    end as progress
                from enrollments e
                join users u on u.id = e.user_id and u.dismissed_at is null
                join courses c on c.id = e.course_id and c.deleted_at is null
                left join lesson_counts lessons on lessons.course_id = e.course_id
                left join lesson_completions done on done.enrollment_id = e.id
                group by e.id, e.course_id, e.user_id, e.completed_at, lessons.lessons
            )
            SQL;
    }
}
