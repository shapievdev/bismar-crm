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
     * Сколько человек уезжает в поимённый список за одной цифрой.
     *
     * Записей на курсы в компании тысячи, и «все» здесь означало бы страницу,
     * которую не прокрутить и не дождаться. Сколько их на самом деле,
     * возвращается рядом (`total`) — отрезанное должно быть названо, иначе
     * двести строк читаются как «вот и все».
     */
    private const PEOPLE = 200;

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

        $plans = DB::selectOne(sprintf(<<<'SQL'
            select
                count(*) as steps,
                count(distinct user_id) as people,
                count(*) filter (where done) as done
            from (
                select i.user_id, %s as done
                from learning_plan_items i
                join users u on u.id = i.user_id and u.dismissed_at is null
            ) steps
            SQL, $this->planStepDoneSql()));

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
     * Люди за цифрой: кого именно посчитали в сводке.
     *
     * Своим адресом, а не внутри общего ответа: список — персональные данные,
     * и присылать семь списков всякому, кто открыл сводку, незачем. Раскрывают
     * из них один, и то не всегда.
     *
     * Строка у всех срезов одна: человек, материал, состояние, доля и дата.
     * Разные срезы отвечают на разные вопросы, но читают их одной таблицей, и
     * семь таблиц на одном экране означали бы семь способов прочитать фамилию.
     *
     * Порядок везде «сначала худшее»: отчёт открывают ради тех, с кем надо
     * разговаривать, а не ради отличников.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function people(string $slice): array
    {
        return match ($slice) {
            'completed' => $this->completedEnrollments(),
            'progress' => $this->allEnrollments(),
            'learners' => $this->learners(),
            'plan' => $this->planSteps(),
            'acknowledgements' => $this->acknowledgements(),
            'attestations' => $this->attestationsAwaiting(),
            default => $this->untouchedEnrollments(),
        };
    }

    /**
     * Записи, к которым не приступали.
     *
     * Первыми — самые давние: запись, висящая с весны, это не «человек ещё не
     * успел», а «назначили и забыли», и разговор по ней другой.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function untouchedEnrollments(): array
    {
        return $this->slice(sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                c.title,
                c.slug,
                e.enrolled_at as at
            from enrollments e
            join users u on u.id = e.user_id and u.dismissed_at is null
            join courses c on c.id = e.course_id and c.deleted_at is null
            where e.started_at is null and e.completed_at is null
            order by e.enrolled_at, %s
            limit %d
            SQL, $this->nameSql(), $this->nameOrderSql(), self::PEOPLE), [], fn (object $row): array => [
            ...$this->who($row),
            'title' => $row->title,
            'path' => '/lms/'.$row->slug,
            'state' => 'не приступал',
            'tone' => 'warning',
            'progress' => 0,
            'at' => $this->when($row->at),
        ]);
    }

    /**
     * Пройденные записи — свежие сверху: здесь смотрят, что происходит сейчас.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function completedEnrollments(): array
    {
        return $this->slice(sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                c.title,
                c.slug,
                e.completed_at as at
            from enrollments e
            join users u on u.id = e.user_id and u.dismissed_at is null
            join courses c on c.id = e.course_id and c.deleted_at is null
            where e.completed_at is not null
            order by e.completed_at desc
            limit %d
            SQL, $this->nameSql(), self::PEOPLE), [], fn (object $row): array => [
            ...$this->who($row),
            'title' => $row->title,
            'path' => '/lms/'.$row->slug,
            'state' => 'пройден',
            'tone' => 'success',
            'progress' => 100,
            'at' => $this->when($row->at),
        ]);
    }

    /**
     * Все записи с прогрессом — то самое, из чего сложился средний.
     *
     * Сначала те, у кого меньше: средний процент открывают, чтобы узнать, кто
     * его тянет вниз.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function allEnrollments(): array
    {
        return $this->slice($this->progressSql().sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                c.title,
                c.slug,
                p.progress,
                p.started_at,
                p.completed_at
            from progress p
            join users u on u.id = p.user_id
            join courses c on c.id = p.course_id
            order by p.progress, %s
            limit %d
            SQL, $this->nameSql(), $this->nameOrderSql(), self::PEOPLE), [], fn (object $row): array => [
            ...$this->who($row),
            'title' => $row->title,
            'path' => '/lms/'.$row->slug,
            'state' => match (true) {
                $row->completed_at !== null => 'пройден',
                $row->started_at !== null => 'идёт',
                default => 'не приступал',
            },
            'tone' => match (true) {
                $row->completed_at !== null => 'success',
                $row->started_at !== null => 'muted',
                default => 'warning',
            },
            'progress' => (int) $row->progress,
            'at' => $this->when($row->completed_at ?? $row->started_at),
        ]);
    }

    /**
     * Ученики — по человеку, а не по записи: вопрос «кто учится» задают о людях.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function learners(): array
    {
        return $this->slice($this->progressSql().sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                count(*) as courses,
                count(*) filter (where p.completed_at is not null) as completed,
                coalesce(round(avg(p.progress)), 0) as progress,
                max(coalesce(p.completed_at, p.started_at)) as at
            from progress p
            join users u on u.id = p.user_id
            group by u.id, u.last_name, u.first_name, u.middle_name
            order by coalesce(round(avg(p.progress)), 0), %s
            limit %d
            SQL, $this->nameSql(), $this->nameOrderSql(), self::PEOPLE), [], fn (object $row): array => [
            ...$this->who($row),

            // Словами, а не двумя числами в разных колонках: «пройдено 1 из 3»
            // — это один факт, и разрезать его по таблице значит заставить
            // читателя сложить его обратно.
            'title' => sprintf('Пройдено %d из %d', (int) $row->completed, (int) $row->courses),
            'path' => null,
            'state' => (int) $row->completed === (int) $row->courses ? 'всё пройдено' : 'учится',
            'tone' => (int) $row->completed === (int) $row->courses ? 'success' : 'muted',
            'progress' => (int) $row->progress,
            'at' => $this->when($row->at),
        ]);
    }

    /**
     * Шаги планов обучения — сначала непройденные.
     *
     * План работает запретом: пока шаг не закрыт, человеку закрыты все курсы,
     * кроме текущего. Поэтому непройденный шаг здесь — не «медленно идёт», а
     * запертый каталог, и стоит он первым.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function planSteps(): array
    {
        return $this->slice(sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                i.position,
                i.plannable_type,
                i.created_at as at,
                c.title as course_title,
                c.slug as course_slug,
                r.title as document_title,
                r.slug as document_slug,
                r.kind as document_kind,
                %s as done
            from learning_plan_items i
            join users u on u.id = i.user_id and u.dismissed_at is null
            left join courses c
                on i.plannable_type = 'course' and c.id = i.plannable_id and c.deleted_at is null
            left join regulations r
                on i.plannable_type = 'regulation' and r.id = i.plannable_id and r.deleted_at is null
            order by done, %s, i.position
            limit %d
            SQL, $this->nameSql(), $this->planStepDoneSql(), $this->nameOrderSql(), self::PEOPLE), [], function (object $row): array {
            $isCourse = $row->plannable_type === 'course';

            return [
                ...$this->who($row),

                // Материала может уже не быть: шаг остаётся, пока план не
                // переписали, и молчать о нём хуже, чем назвать пропажу.
                'title' => ($isCourse ? $row->course_title : $row->document_title) ?? 'материал удалён',
                'path' => $isCourse
                    ? ($row->course_slug === null ? null : '/lms/'.$row->course_slug)
                    : $this->materialPath($row->document_kind, $row->document_slug),
                'state' => $row->done ? 'пройден' : 'в очереди',
                'tone' => $row->done ? 'success' : 'warning',
                'progress' => null,
                'at' => $this->when($row->at),
            ];
        });
    }

    /**
     * Ознакомления с документами и справочниками — свежие сверху.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function acknowledgements(): array
    {
        return $this->slice(sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                r.title,
                r.slug,
                r.kind,
                v.name as version_name,
                a.acknowledged_at as at
            from regulation_acknowledgements a
            join users u on u.id = a.user_id and u.dismissed_at is null
            join regulations r on r.id = a.regulation_id and r.deleted_at is null
            left join regulation_versions v on v.id = a.version_id
            order by a.acknowledged_at desc
            limit %d
            SQL, $this->nameSql(), self::PEOPLE), [], fn (object $row): array => [
            ...$this->who($row),

            // Чью версию прочли: в документе с версиями «ознакомлен» без имени
            // текста не говорит, с чем именно.
            'title' => $row->version_name === null
                ? $row->title
                : sprintf('%s · версия «%s»', $row->title, $row->version_name),
            'path' => $this->materialPath($row->kind, $row->slug),
            'state' => 'ознакомлен',
            'tone' => 'success',
            'progress' => null,
            'at' => $this->when($row->at),
        ]);
    }

    /**
     * Работы, ждущие человека, — сначала самые давние.
     *
     * Это единственный срез, где спрашивают не с сотрудника: работа отправлена,
     * и ждут здесь проверяющего. Ведёт строка в материал, а не в очередь
     * проверки: очередь у каждого своя — в ней лежит только сданное ему.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function attestationsAwaiting(): array
    {
        return $this->slice(sprintf(<<<'SQL'
            select
                count(*) over () as total,
                %s,
                q.title,
                q.quizzable_type as owner,
                l.id as lesson_id,
                c.slug as course_slug,
                coalesce(r.slug, vr.slug) as document_slug,
                coalesce(r.kind, vr.kind) as document_kind,
                t.completed_at as at
            from quiz_attempts t
            join users u on u.id = t.user_id and u.dismissed_at is null
            join quizzes q on q.id = t.quiz_id
            left join lessons l on q.quizzable_type = 'lesson' and l.id = q.quizzable_id
            left join course_modules m on m.id = l.module_id
            left join courses c on c.id = m.course_id and c.deleted_at is null
            left join regulations r on q.quizzable_type = 'regulation' and r.id = q.quizzable_id
                and r.deleted_at is null
            left join regulation_versions v on q.quizzable_type = 'regulation_version'
                and v.id = q.quizzable_id
            left join regulations vr on vr.id = v.regulation_id and vr.deleted_at is null
            where t.review_status = ?
            order by t.completed_at
            limit %d
            SQL, $this->nameSql(), self::PEOPLE), [AttestationStatus::Pending->value], fn (object $row): array => [
            ...$this->who($row),
            'title' => $row->title,
            'path' => $row->owner === 'lesson'
                ? ($row->course_slug === null || $row->lesson_id === null
                    ? null
                    : '/lms/'.$row->course_slug.'/lessons/'.$row->lesson_id)
                : $this->materialPath($row->document_kind, $row->document_slug),
            'state' => AttestationStatus::Pending->label(),
            'tone' => 'warning',
            'progress' => null,
            'at' => $this->when($row->at),
        ]);
    }

    /* ---------- Кухня ---------- */

    /**
     * Строки среза и сколько их всего.
     *
     * Общее число считается окном по тому же запросу, а не вторым `count(*)`:
     * второй запрос ходил бы по тем же соединениям ради одного числа — и мог бы
     * посчитать уже другое, если между ними кто-то дошёл до конца курса.
     *
     * @param  list<mixed>  $bindings
     * @param  callable(object): array<string, mixed>  $shape
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function slice(string $sql, array $bindings, callable $shape): array
    {
        $rows = DB::select($sql, $bindings);

        return [
            'total' => $rows === [] ? 0 : (int) $rows[0]->total,
            'rows' => array_map($shape, $rows),
        ];
    }

    /** Колонки, из которых складывается имя. */
    private function nameSql(): string
    {
        return 'u.id as user_id, u.last_name, u.first_name, u.middle_name';
    }

    /** Порядок по фамилии — русской коллацией: без неё «Яшин» идёт перед «Wood». */
    private function nameOrderSql(): string
    {
        return 'coalesce(u.last_name, u.first_name) collate "und-x-icu"';
    }

    /**
     * Человек в строке списка.
     *
     * @return array{user_id: int, name: string}
     */
    private function who(object $row): array
    {
        return [
            'user_id' => (int) $row->user_id,
            'name' => trim(implode(' ', array_filter([
                $row->last_name ?? '',
                $row->first_name,
                $row->middle_name,
            ]))),
        ];
    }

    /** Дата как есть: как её показать, решает экран. */
    private function when(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Адрес документа или справочника.
     *
     * Раздел берётся у вида (MaterialKind::section()) — того же места, что
     * знает Regulation::path(): разделов два, и собранная по месту ссылка
     * однажды уведёт справочник в документы.
     */
    private function materialPath(?string $kind, ?string $slug): ?string
    {
        if ($kind === null || $slug === null) {
            return null;
        }

        return '/lms/'.MaterialKind::from($kind)->section().'/'.$slug;
    }

    /**
     * Пройден ли шаг плана — одно выражение на сводку и на поимённый список.
     *
     * Врозь они разошлись бы на первой правке, и «пройдено 3 из 6» стояло бы
     * над списком, где пройденных четыре.
     *
     * Это упрощённый брат App\Support\Lms\StepCompletion: тот отвечает за
     * запрет и спрашивает у документа ещё и сданную проверку, здесь же считают
     * отчёт, и лишний join на каждый шаг ради двух процентов разницы не окупает
     * себя. Расходятся они в одну сторону — отчёт добрее запрета.
     */
    private function planStepDoneSql(): string
    {
        return <<<'SQL'
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
            end
            SQL;
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
