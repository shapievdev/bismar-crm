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
     * Тесты: сколько человек их проходило и сколько сдало.
     *
     * Один список на оба вида — тесты уроков и проверки документов: у них общее
     * устройство и общий вопрос «как это проходят», а разбирать отчёт по двум
     * таблицам значило бы читать его в две колонки.
     *
     * Сдавшие считаются по людям, а не по попыткам: сдал со третьего раза —
     * всё равно сдал один человек. Средний балл берётся по лучшей попытке
     * каждого: по всем подряд он говорил бы о том, сколько раз человек
     * пробовал, а не о том, чем кончилось.
     *
     * @return list<array<string, mixed>>
     */
    public function quizzes(): array
    {
        $rows = DB::select(sprintf(<<<'SQL'
            with best as (
                select a.quiz_id, a.user_id, max(a.score) as best_score, bool_or(a.passed) as passed
                from quiz_attempts a
                join users u on u.id = a.user_id and u.dismissed_at is null
                group by a.quiz_id, a.user_id
            )
            select
                q.id,
                q.title,
                q.quizzable_type as kind,
                q.quizzable_id,
                (select count(*) from quiz_questions where quiz_id = q.id) as questions,
                l.id as lesson_id,
                l.title as lesson_title,
                c.slug as course_slug,
                c.title as course_title,
                r.slug as document_slug,
                r.title as document_title,
                count(best.user_id) as attempted,
                count(best.user_id) filter (where best.passed) as passed,
                coalesce(round(avg(best.best_score)), 0) as average_score
            from quizzes q
            left join best on best.quiz_id = q.id
            left join lessons l on q.quizzable_type = 'lesson' and l.id = q.quizzable_id
            left join course_modules m on m.id = l.module_id
            left join courses c on c.id = m.course_id and c.deleted_at is null
            left join regulations r on q.quizzable_type = 'regulation' and r.id = q.quizzable_id
                and r.deleted_at is null
            group by q.id, q.title, q.quizzable_type, q.quizzable_id,
                l.id, l.title, c.slug, c.title, r.slug, r.title
            order by count(best.user_id) desc, q.title collate "und-x-icu"
            limit %d
            SQL, self::TOP));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,

            // Где тест стоит: экран рисует по этому и подпись, и ссылку —
            // «Кассовая дисциплина» ведёт в документ, урок — в курс.
            'kind' => $row->kind,
            'material' => $row->kind === 'lesson' ? $row->lesson_title : $row->document_title,
            'course_title' => $row->course_title,
            'course_slug' => $row->course_slug,
            'lesson_id' => $row->lesson_id === null ? null : (int) $row->lesson_id,
            'document_slug' => $row->document_slug,

            'questions' => (int) $row->questions,
            'attempted' => (int) $row->attempted,
            'passed' => (int) $row->passed,
            'average_score' => (int) $row->average_score,
        ], $rows);
    }

    /**
     * Кто и как прошёл один тест.
     *
     * По человеку, а не по попытке: в отчёте спрашивают «сдал ли Иванов», а не
     * «что он отправлял в третий раз». Попытки при этом посчитаны — по их числу
     * видно, далась ли проверка с первого раза.
     *
     * @return list<array<string, mixed>>
     */
    public function quizResults(int $quizId): array
    {
        $rows = DB::select(<<<'SQL'
            select
                u.id,
                coalesce(u.last_name, '') as last_name,
                u.first_name,
                u.middle_name,
                count(a.id) as attempts,
                max(a.score) as best_score,
                bool_or(a.passed) as passed,
                max(a.completed_at) as last_at
            from quiz_attempts a
            join users u on u.id = a.user_id and u.dismissed_at is null
            where a.quiz_id = ?
            group by u.id, u.last_name, u.first_name, u.middle_name
            order by bool_or(a.passed), coalesce(u.last_name, u.first_name) collate "und-x-icu"
            SQL, [$quizId]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => trim(implode(' ', array_filter([$row->last_name, $row->first_name, $row->middle_name]))),
            'attempts' => (int) $row->attempts,
            'best_score' => (int) $row->best_score,
            'passed' => (bool) $row->passed,
            'last_at' => $row->last_at === null ? null : (string) $row->last_at,
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
