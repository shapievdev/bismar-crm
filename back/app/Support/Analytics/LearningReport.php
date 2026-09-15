<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\AttestationStatus;
use App\Enums\CourseStatus;
use App\Enums\MaterialKind;
use App\Enums\QuizKind;
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
 *
 * **Главная величина здесь — охват, а не счёт.** «Курс прошли семеро» не
 * значит ничего, пока не сказано, из скольких; семеро из семи и семеро из
 * сорока — это разные новости, и решения по ним противоположные. Кто считается
 * допущенным — см. circleSql().
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
                (select count(*) from regulations where deleted_at is null and kind = ?) as documents,
                (
                    select count(*) from regulations
                    where deleted_at is null and kind = ? and status = ?
                ) as published_documents,
                (select count(*) from regulations where deleted_at is null and kind = ?) as handbooks,
                (
                    select count(*) from regulations
                    where deleted_at is null and kind = ? and status = ?
                ) as published_handbooks,
                (
                    select count(v.id)
                    from regulation_versions v
                    join regulations r on r.id = v.regulation_id and r.deleted_at is null
                ) as versions,
                (
                    select count(l.id)
                    from lessons l
                    join course_modules m on m.id = l.module_id
                    join courses c on c.id = m.course_id and c.deleted_at is null
                ) as lessons
            SQL, [
            CourseStatus::Published->value,
            MaterialKind::Document->value,
            MaterialKind::Document->value, CourseStatus::Published->value,
            MaterialKind::Handbook->value,
            MaterialKind::Handbook->value, CourseStatus::Published->value,
        ]);

        /*
         * Назначено, начато, пройдено — три разных числа, и разница между
         * первыми двумя важнее прочего: запись, к которой не приступали, это
         * не «медленно идёт», а «не открывали вовсе», и разговаривать по ней
         * надо иначе.
         */
        $learning = DB::selectOne($this->progressSql().<<<'SQL'
            select
                count(*) as enrollments,
                count(distinct user_id) as learners,
                count(*) filter (where started_at is null and completed_at is null) as not_started,
                count(*) filter (where completed_at is not null) as completed,
                coalesce(round(avg(progress)), 0) as average_progress
            from progress
            SQL);

        $quizzes = DB::selectOne(<<<'SQL'
            select
                count(*) as attempts,
                count(*) filter (where a.passed) as passed,
                coalesce(round(avg(a.score)), 0) as average_score,
                count(*) filter (where a.review_status = ?) as pending
            from quiz_attempts a
            join users u on u.id = a.user_id and u.dismissed_at is null
            SQL, [AttestationStatus::Pending->value]);

        $attestations = DB::selectOne(<<<'SQL'
            select count(*) as total from quizzes where kind = ?
            SQL, [QuizKind::Attestation->value]);

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
            'lessons' => (int) $material->lessons,

            // Документы и справочники — разные разделы со своими правами
            // (2026-09-11), и складывать их в одно число значит отвечать на
            // вопрос, которого никто не задавал.
            'documents' => (int) $material->documents,
            'published_documents' => (int) $material->published_documents,
            'handbooks' => (int) $material->handbooks,
            'published_handbooks' => (int) $material->published_handbooks,
            'versions' => (int) $material->versions,

            'enrollments' => (int) $learning->enrollments,
            'learners' => (int) $learning->learners,
            'not_started' => (int) $learning->not_started,
            'completed' => (int) $learning->completed,
            'average_progress' => (int) $learning->average_progress,

            'quiz_attempts' => (int) $quizzes->attempts,
            'quiz_passed' => (int) $quizzes->passed,
            'quiz_average_score' => (int) $quizzes->average_score,

            // Аттестации проверяет человек, и отчёт открывают в том числе
            // затем, чтобы узнать, не ждёт ли кто-то этой проверки.
            'attestations' => (int) $attestations->total,
            'attestations_pending' => (int) $quizzes->pending,

            'acknowledgements' => (int) $acknowledgements->total,
            'acknowledged_by' => (int) $acknowledgements->people,

            'plan_people' => (int) $plans->people,
            'plan_steps' => (int) $plans->steps,
            'plan_done' => (int) $plans->done,
        ];
    }

    /**
     * Курсы: кого допустили, кто приступил и кто дошёл до конца.
     *
     * Порядок — по тому, где хуже: сначала курсы, у которых больше всего
     * допущенных не дошло. Рейтинг «где больше записей» показывал самый
     * многолюдный курс, а не тот, которым надо заняться.
     *
     * @return list<array<string, mixed>>
     */
    public function courses(): array
    {
        $rows = DB::select($this->progressSql().$this->courseCircleSql().sprintf(<<<'SQL'
            select
                c.id,
                c.title,
                c.slug,
                c.status,
                coalesce(lessons.lessons, 0) as lessons,
                coalesce(circle.people, 0) as audience,
                count(p.id) as enrolled,
                count(p.id) filter (where p.started_at is not null or p.completed_at is not null) as started,
                count(p.id) filter (where p.completed_at is not null) as completed,
                coalesce(round(avg(p.progress)), 0) as average_progress
            from courses c
            left join lesson_counts lessons on lessons.course_id = c.id
            left join course_circle circle on circle.course_id = c.id
            left join progress p on p.course_id = c.id
            where c.deleted_at is null
            group by c.id, c.title, c.slug, c.status, lessons.lessons, circle.people
            order by
                coalesce(circle.people, 0) - count(p.id) filter (where p.completed_at is not null) desc,
                coalesce(circle.people, 0) desc,
                c.title collate "und-x-icu"
            limit %d
            SQL, self::TOP));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'is_published' => $row->status === CourseStatus::Published->value,
            'lessons' => (int) $row->lessons,
            'audience' => (int) $row->audience,
            'enrolled' => (int) $row->enrolled,
            'started' => (int) $row->started,
            'completed' => (int) $row->completed,
            'average_progress' => (int) $row->average_progress,
        ], $rows);
    }

    /**
     * Документы или справочники: кого допустили и кто ознакомился.
     *
     * Раздельно, а не одним списком: у разделов свои права и свои читатели, и
     * пятнадцать строк на двоих означали бы, что справочники вытеснят
     * документы или наоборот.
     *
     * @return list<array<string, mixed>>
     */
    public function materials(MaterialKind $kind): array
    {
        $rows = DB::select($this->regulationCircleSql().sprintf(<<<'SQL'
            select
                r.id,
                r.title,
                r.slug,
                r.status,
                coalesce(circle.people, 0) as audience,
                (
                    select count(*) from regulation_versions v where v.regulation_id = r.id
                ) as versions,
                count(distinct a.user_id) filter (where u.dismissed_at is null) as acknowledged
            from regulations r
            left join regulation_circle circle on circle.regulation_id = r.id
            left join regulation_acknowledgements a on a.regulation_id = r.id
            left join users u on u.id = a.user_id
            where r.deleted_at is null and r.kind = ?
            group by r.id, r.title, r.slug, r.status, circle.people
            order by
                coalesce(circle.people, 0) - count(distinct a.user_id) filter (where u.dismissed_at is null) desc,
                coalesce(circle.people, 0) desc,
                r.title collate "und-x-icu"
            limit %d
            SQL, self::TOP), [$kind->value]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'is_published' => $row->status === CourseStatus::Published->value,
            'audience' => (int) $row->audience,

            // Сколько версий: у документа с версиями каждый читает свою, и
            // «один документ» здесь — это несколько разных текстов.
            'versions' => (int) $row->versions,

            'acknowledged' => (int) $row->acknowledged,
        ], $rows);
    }

    /**
     * Проверки: сколько человек их проходило и сколько сдало.
     *
     * Один список на все три вида владельца — тест урока, проверка документа и
     * проверка версии: у них общее устройство и общий вопрос «как это
     * проходят», а разбирать отчёт по трём таблицам значило бы читать его в три
     * колонки. Версии сюда попали не сразу: заведённые вместе с ними проверки
     * год не показывались нигде.
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
                select
                    a.quiz_id,
                    a.user_id,
                    max(a.score) as best_score,
                    bool_or(a.passed) as passed,
                    bool_or(a.review_status = ?) as awaiting
                from quiz_attempts a
                join users u on u.id = a.user_id and u.dismissed_at is null
                group by a.quiz_id, a.user_id
            )
            select
                q.id,
                q.title,
                q.kind as quiz_kind,
                q.quizzable_type as owner,
                l.id as lesson_id,
                l.title as lesson_title,
                c.slug as course_slug,
                c.title as course_title,
                coalesce(r.slug, vr.slug) as document_slug,
                coalesce(r.kind, vr.kind) as document_kind,
                coalesce(r.title, vr.title) as document_title,
                v.name as version_name,
                (select count(*) from quiz_questions where quiz_id = q.id) as questions,
                count(best.user_id) as attempted,
                count(best.user_id) filter (where best.passed) as passed,
                count(best.user_id) filter (where best.awaiting) as pending,
                coalesce(round(avg(best.best_score)), 0) as average_score
            from quizzes q
            left join best on best.quiz_id = q.id
            left join lessons l on q.quizzable_type = 'lesson' and l.id = q.quizzable_id
            left join course_modules m on m.id = l.module_id
            left join courses c on c.id = m.course_id and c.deleted_at is null
            left join regulations r on q.quizzable_type = 'regulation' and r.id = q.quizzable_id
                and r.deleted_at is null
            left join regulation_versions v on q.quizzable_type = 'regulation_version'
                and v.id = q.quizzable_id
            left join regulations vr on vr.id = v.regulation_id and vr.deleted_at is null
            group by q.id, q.title, q.kind, q.quizzable_type,
                l.id, l.title, c.slug, c.title,
                r.slug, r.kind, r.title, vr.slug, vr.kind, vr.title, v.name
            order by
                count(best.user_id) filter (where best.awaiting) desc,
                count(best.user_id) - count(best.user_id) filter (where best.passed) desc,
                q.title collate "und-x-icu"
            limit %d
            SQL, self::TOP), [AttestationStatus::Pending->value]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => $row->title,

            // Аттестацию читает человек, и «не сдал» у неё означает «ещё не
            // смотрели», пока проверка не пройдена.
            'is_attestation' => $row->quiz_kind === QuizKind::Attestation->value,

            // Где проверка стоит: экран рисует по этому и подпись, и ссылку —
            // «Кассовая дисциплина» ведёт в документ, урок — в курс.
            'owner' => $row->owner,
            'material' => $row->owner === 'lesson' ? $row->lesson_title : $row->document_title,
            'course_title' => $row->course_title,
            'course_slug' => $row->course_slug,
            'lesson_id' => $row->lesson_id === null ? null : (int) $row->lesson_id,
            'document_slug' => $row->document_slug,

            // Документ или справочник: разделы разные, и ссылка без этого
            // увела бы в чужой.
            'document_kind' => $row->document_kind,

            // У проверки версии — чья она: в документе с версиями иначе не
            // понять, о каком тексте речь.
            'version_name' => $row->version_name,

            'questions' => (int) $row->questions,
            'attempted' => (int) $row->attempted,
            'passed' => (int) $row->passed,
            'pending' => (int) $row->pending,
            'average_score' => (int) $row->average_score,
        ], $rows);
    }

    /**
     * Кто и как прошёл одну проверку.
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
                bool_or(a.review_status = ?) as awaiting,
                max(a.completed_at) as last_at
            from quiz_attempts a
            join users u on u.id = a.user_id and u.dismissed_at is null
            where a.quiz_id = ?
            group by u.id, u.last_name, u.first_name, u.middle_name
            order by bool_or(a.passed), coalesce(u.last_name, u.first_name) collate "und-x-icu"
            SQL, [AttestationStatus::Pending->value, $quizId]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => trim(implode(' ', array_filter([$row->last_name, $row->first_name, $row->middle_name]))),
            'attempts' => (int) $row->attempts,
            'best_score' => (int) $row->best_score,
            'passed' => (bool) $row->passed,

            // Ждёт проверки: у аттестации это не «не сдал», а «до него ещё не
            // дошли руки», и спрашивать надо не с него.
            'awaiting' => (bool) $row->awaiting,

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
                    e.started_at,
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
                group by e.id, e.course_id, e.user_id, e.started_at, e.completed_at, lessons.lessons
            )
            SQL;
    }

    /**
     * Круг допущенных к курсу: записанные плюс те, кому курс назначен планом.
     *
     * То же определение, что у поимённого отчёта внизу страницы курса
     * (App\Support\Lms\ProgressReport). Разойдись они — и на одной странице
     * стояло бы «семеро из девятнадцати», а на другой двадцать одна строка, и
     * верить перестали бы обеим.
     *
     * `union`, а не `union all`: назначенный планом и записавшийся — один
     * человек, и считать его дважды значит завысить знаменатель.
     */
    private function courseCircleSql(): string
    {
        return <<<'SQL'
            , course_circle as (
                select course_id, count(*) as people
                from (
                    select e.course_id, e.user_id
                    from enrollments e
                    join users u on u.id = e.user_id and u.dismissed_at is null

                    union

                    select i.plannable_id as course_id, i.user_id
                    from learning_plan_items i
                    join users u on u.id = i.user_id and u.dismissed_at is null
                    where i.plannable_type = 'course'
                ) circle
                group by course_id
            )
            SQL;
    }

    /**
     * Круг допущенных к документу: ознакомившиеся, назначенные планом и те, кто
     * отправлял ответы на проверку.
     *
     * Третьи — не лишние: не сдавший проверку не ознакомлен, но к документу он
     * приходил, и не считать его значит потерять ровно того, с кем надо
     * разговаривать. Проверок у документа с версиями столько, сколько версий, —
     * поэтому смотрим и на её собственную, и на версионные.
     */
    private function regulationCircleSql(): string
    {
        return <<<'SQL'
            with regulation_circle as (
                select regulation_id, count(*) as people
                from (
                    select a.regulation_id, a.user_id
                    from regulation_acknowledgements a
                    join users u on u.id = a.user_id and u.dismissed_at is null

                    union

                    select i.plannable_id as regulation_id, i.user_id
                    from learning_plan_items i
                    join users u on u.id = i.user_id and u.dismissed_at is null
                    where i.plannable_type = 'regulation'

                    union

                    select q.quizzable_id as regulation_id, t.user_id
                    from quiz_attempts t
                    join quizzes q on q.id = t.quiz_id and q.quizzable_type = 'regulation'
                    join users u on u.id = t.user_id and u.dismissed_at is null

                    union

                    select v.regulation_id, t.user_id
                    from quiz_attempts t
                    join quizzes q on q.id = t.quiz_id and q.quizzable_type = 'regulation_version'
                    join regulation_versions v on v.id = q.quizzable_id
                    join users u on u.id = t.user_id and u.dismissed_at is null
                ) circle
                group by regulation_id
            )
            SQL;
    }
}
