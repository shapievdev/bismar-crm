<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AnswerSource;
use App\Enums\CourseStatus;
use App\Enums\Permission;
use App\Models\LessonAttachment;
use App\Support\Lms\CourseAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Все таблицы уроков как один общий указатель.
 *
 * Каждый урок несёт свою таблицу «вопрос — ответ — источник», но искать по ним
 * поурочно бессмысленно: сотрудник не знает, в каком уроке его вопрос разобран,
 * — за тем и спрашивает. Поэтому строки всех уроков рассматриваются вместе, как
 * одна таблица на всю базу.
 *
 * Материал только опубликованный и только открытый спрашивающему, как и в
 * KnowledgeBase: пересказ черновика — или чужого приватного курса — выдаёт его
 * так же верно, как показ страницы.
 */
final readonly class CuratedAnswers
{
    /**
     * Что нужно знать о строке, чтобы её показать и на неё сослаться.
     *
     * Названия урока и курса — не украшение: «источник 3» без имени урока
     * проверить негде, а модели название курса говорит половину смысла строки.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'lesson_answers.id',
        'lesson_answers.question',
        'lesson_answers.answer',
        'lesson_answers.source_kind',
        'lesson_answers.source_seconds',
        'lesson_answers.source_page',
        'lesson_answers.source_block_id',
        'lessons.id as lesson_id',
        'lessons.title as lesson_title',
        'courses.title as course_title',
        'courses.slug as course_slug',
        'attachments.id as attachment_id',
        'attachments.name as attachment_name',
        'attachments.disk as attachment_disk',
        'attachments.path as attachment_path',
        'attachments.mime_type as attachment_mime',
    ];

    /**
     * Строки, отвечающие на вопрос, — лучшая первой, а за ними близкие.
     *
     * Близкие — это те, что прошли широкий порог отбора, но не прошли узкий:
     * строка про соседний случай, разбор смежной темы. Отвечать по ним нельзя,
     * а промолчать о них — значит скрыть от сотрудника то, что база знает.
     */
    public function search(Asked $asked, int $limit, int $relatedLimit, CourseAccess $access): Retrieved
    {
        if ($asked->text === '' || $limit < 1) {
            return new Retrieved;
        }

        $relatedLimit = max($relatedLimit, 0);

        return $asked->isSemantic()
            ? $this->byMeaning($asked, $limit, $relatedLimit, $access)
            : $this->byWords($asked->text, $limit, $relatedLimit, $access);
    }

    /**
     * Достаточно ли уверенно лучшая строка совпала, чтобы отдать её дословно.
     *
     * Двух условий, а не одного. Порога мало: две похоже сформулированные
     * строки могут обе его перевалить, и тогда выдача молча выбирает одну из
     * двух — а вопрос, на который в базе два разных ответа, как раз тот, где
     * выбирать наугад нельзя. Отрыв от второй это ловит.
     *
     * Оба числа заведомо требуют калибровки на живых вопросах: они зависят от
     * модели эмбеддингов, а не от предметной области.
     *
     * @param  list<CuratedAnswer>  $matches
     */
    public function isVerbatim(array $matches, Asked $asked): bool
    {
        $best = $matches[0] ?? null;

        // Словесный поиск даёт ts_rank, а не близость: сравнивать его с порогом
        // нельзя — шкалы разные. Дословная выдача — только по смыслу, и
        // спрашивать об этом надо у самого вопроса: настроенная модель
        // эмбеддингов не обещает, что вектор в этот раз посчитан.
        if ($best === null || ! $asked->isSemantic()) {
            return false;
        }

        if ($best->score < (float) config('ai.verbatim_threshold')) {
            return false;
        }

        $runnerUp = $matches[1] ?? null;

        return $runnerUp === null
            || ($best->score - $runnerUp->score) >= (float) config('ai.verbatim_margin');
    }

    /**
     * Смысловой поиск: ближайшие к вопросу строки, найденные самой базой.
     *
     * Прежде здесь шёл полный проход — на каждый вопрос вычитывались все
     * опубликованные строки с обоими векторами, и близость считалась в PHP.
     * Работало, пока строк были сотни; на двадцати тысячах это сто мегабайт по
     * сети и сорок тысяч скалярных произведений за один вопрос сотрудника.
     * Теперь расстояние считает Postgres и берёт для этого HNSW-индекс.
     *
     * Векторов у строки два, и индексов, соответственно, тоже два. Отсюда две
     * ветки поиска вместо одной: индекс строится по колонке, и «ближайшие по
     * любому из двух» одним обращением не выражается. Лучшее из двух расстояний
     * берётся уже над их объединением.
     *
     * Почему лучшее, а не среднее: вопрос сотрудника бывает сформулирован и
     * словами вопроса строки («как подобрать краску»), и словами её ответа
     * («сохнет четыре часа»). Среднее наказывало бы за то, что совпало одно, —
     * хотя этого ровно и достаточно.
     *
     * Отбор один на оба порога. Широкий отбирает всё, что вообще про эту тему,
     * узкий — то, чем можно отвечать; второй раз идти в базу ради близкого было
     * бы вдвое дороже ровно за те же строки.
     */
    private function byMeaning(Asked $question, int $limit, int $relatedLimit, CourseAccess $access): Retrieved
    {
        $floor = min(
            (float) config('ai.answers_floor'),
            (float) config('ai.answers_related_floor'),
        );

        $keep = $limit + $relatedLimit;
        $best = $this->nearest($question, $keep, $floor, $access);

        $confident = array_values(array_filter(
            $best,
            static fn (CuratedAnswer $one): bool => $one->score >= (float) config('ai.answers_floor'),
        ));

        $exact = $this->closeToTheBest($confident);
        $answering = array_map(static fn (CuratedAnswer $one): int => $one->answerId, $exact);

        // Близкое — всё остальное, что прошло широкий порог: и не дотянувшее до
        // узкого, и отсеянное отрывом от лучшей строки.
        $related = array_values(array_filter(
            $best,
            static fn (CuratedAnswer $one): bool => ! in_array($one->answerId, $answering, strict: true),
        ));

        return new Retrieved($exact, array_slice($related, 0, $relatedLimit));
    }

    /**
     * Ближайшие к вопросу строки — по каждому из двух векторов и лучшее из них.
     *
     * @return list<CuratedAnswer>
     */
    private function nearest(Asked $question, int $keep, float $floor, CourseAccess $access): array
    {
        $vector = Vector::literal($question->vector);

        // Сколько строк просить у каждого индекса. С запасом, потому что
        // видимость проверяется поверх обхода индекса и часть найденного
        // отсеется как закрытая от спрашивающего. Когда базы станет столько,
        // что запаса перестанет хватать, поднимают hnsw.ef_search — число здесь
        // растить бессмысленно, оно лишь просит у индекса больше того же.
        $perLeg = $keep * 4;

        $visible = sprintf(
            'courses.status = ? AND courses.deleted_at IS NULL%s%s',
            $access->sqlCondition(),
            $this->openToReader($access),
        );

        $legs = [];
        $bindings = [];

        // По ветке на вектор: индекс строится по колонке, и «ближайшие по
        // любому из двух» одним обращением не выражается.
        foreach (['question_embedding', 'answer_embedding'] as $column) {
            $legs[] = sprintf(<<<'SQL'
                (
                    SELECT lesson_answers.id, lesson_answers.%1$s <=> ?::vector AS distance
                    FROM lesson_answers
                    JOIN lessons ON lessons.id = lesson_answers.lesson_id
                    JOIN course_modules ON course_modules.id = lessons.module_id
                    JOIN courses ON courses.id = course_modules.course_id
                    WHERE %2$s AND lesson_answers.%1$s IS NOT NULL
                    ORDER BY lesson_answers.%1$s <=> ?::vector
                    LIMIT ?
                )
            SQL, $column, $visible);

            $bindings = [
                ...$bindings,
                $vector,
                CourseStatus::Published->value,
                ...$access->sqlBindings(),
                $vector,
                $perLeg,
            ];
        }

        $rows = DB::select(sprintf(<<<'SQL'
            WITH nearest AS (
                SELECT id, min(distance) AS distance
                FROM (%1$s) AS found
                GROUP BY id
            )
            SELECT %2$s, 1 - nearest.distance AS score
            FROM nearest
            JOIN lesson_answers ON lesson_answers.id = nearest.id
            JOIN lessons ON lessons.id = lesson_answers.lesson_id
            JOIN course_modules ON course_modules.id = lessons.module_id
            JOIN courses ON courses.id = course_modules.course_id
            LEFT JOIN lesson_attachments AS attachments
                ON attachments.id = lesson_answers.source_attachment_id
            WHERE 1 - nearest.distance >= ?
            ORDER BY score DESC, lesson_answers.id
            LIMIT ?
        SQL,
            implode(' UNION ALL ', $legs),
            implode(', ', self::COLUMNS),
        ), [...$bindings, $floor, $keep]);

        return array_map(
            fn (object $row): CuratedAnswer => $this->hydrate($row, (float) $row->score),
            $rows,
        );
    }

    /**
     * Отбрасывает строки, заметно уступившие лучшей.
     *
     * Порог отсечения отвечает на вопрос «относится ли строка к теме вообще», и
     * ответить на него одним числом на все вопросы нельзя: у одного вопроса
     * лучшее совпадение 0.73, у другого 0.55, и всё, что рядом с ними, разное.
     *
     * Слабой модели это важнее, чем сильной. Получив восемь строк, из которых
     * подходит одна, она отвечает не по лучшей, а по той, что ей приглянулась,
     * — либо решает, что материала нет вовсе. Чем чище переданное, тем меньше
     * ей остаётся решать.
     *
     * @param  list<CuratedAnswer>  $matches
     * @return list<CuratedAnswer>
     */
    private function closeToTheBest(array $matches): array
    {
        $best = $matches[0]->score ?? 0.0;
        $share = (float) config('ai.answers_relative_floor');

        return array_values(array_filter(
            $matches,
            static fn (CuratedAnswer $one): bool => $one->score >= $best * $share,
        ));
    }

    /**
     * Словесный поиск — когда эмбеддинги не настроены или сервис отказал.
     *
     * Деградация та же, что принята для фрагментов: консультант при этом
     * находит хуже, но находит. Молчать из-за отказа вспомогательного сервиса
     * он не должен.
     *
     * Отсечки по величине здесь нет — шкала у ts_rank своя и от вопроса к
     * вопросу разная, — но отрыв от лучшей строки применяется тот же, что и в
     * смысловом поиске: доля от лучшего в этой же выдаче от шкалы не зависит.
     * Без неё запасной путь отдавал моделью всё, что разделило с вопросом хоть
     * одно слово: на «расскажи про водяные насосы» — восемь строк, из которых по
     * делу была одна, а прочие семь заслоняли собой текст самого урока.
     *
     * Близкое здесь — то, что этот отрыв не прошло.
     */
    private function byWords(string $question, int $limit, int $relatedLimit, CourseAccess $access): Retrieved
    {
        $tsquery = $this->tsquery($question);

        if ($tsquery === '') {
            return new Retrieved;
        }

        $document = RussianText::document('lesson_answers.question', 'lesson_answers.answer');

        $rows = $this->rows($access)
            ->selectRaw(sprintf('ts_rank(%s, to_tsquery(\'simple\', ?)) as rank', $document), [$tsquery])
            ->whereRaw(sprintf('%s @@ to_tsquery(\'simple\', ?)', $document), [$tsquery])
            ->orderByDesc('rank')
            ->orderBy('lesson_answers.id')
            ->limit($limit + $relatedLimit)
            ->get();

        $matches = $rows->map(fn (object $row): CuratedAnswer => $this->hydrate($row, (float) $row->rank))->all();

        // Строки идут по убыванию ранга, поэтому прошедшие отрыв — это начало
        // списка, а всё, что за ними, и есть близкое.
        $exact = $this->closeToTheBest(array_slice($matches, 0, $limit));

        return new Retrieved($exact, array_slice($matches, count($exact), $relatedLimit));
    }

    /**
     * Леммы вопроса, собранные в запрос «или».
     *
     * Под конфигурацией `simple`, потому что из русского словаря они уже вышли:
     * второй проход через него стемит стем во что-то, чего в индексе нет. Та же
     * причина изложена в KnowledgeBase, и расходиться этим двум нельзя.
     */
    private function tsquery(string $question): string
    {
        $rows = DB::select(sprintf(
            'SELECT DISTINCT unnest(tsvector_to_array(to_tsvector(\'russian\', %s))) AS lexeme',
            RussianText::normalised('?'),
        ), [$question]);

        return implode(' | ', array_map(
            static fn (object $row): string => "'".str_replace("'", "''", (string) $row->lexeme)."'",
            $rows,
        ));
    }

    /**
     * Открыт ли спрашивающему раздел курсов.
     *
     * Таблицы ответов ведутся при уроках, и другого раздела у них нет: без
     * права на курсы отвечать ими нельзя. Спрашивается это здесь, а не на
     * маршруте, потому что консультанта с 2026-09-11 спрашивает и тот, кому
     * открыты одни справочники, — см. EnsureAnyPermission.
     */
    private function openToReader(CourseAccess $access): string
    {
        return $access->reader()->can(Permission::ViewCourses->value) ? '' : ' AND FALSE';
    }

    private function rows(CourseAccess $access): Builder
    {
        $rows = DB::table('lesson_answers')
            ->select(self::COLUMNS)
            ->join('lessons', 'lessons.id', '=', 'lesson_answers.lesson_id')
            ->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->join('courses', 'courses.id', '=', 'course_modules.course_id')
            ->leftJoin('lesson_attachments as attachments', 'attachments.id', '=', 'lesson_answers.source_attachment_id')
            ->where('courses.status', CourseStatus::Published->value)
            ->whereNull('courses.deleted_at')
            ->when(
                $access->reader()->cannot(Permission::ViewCourses->value),
                fn (Builder $query) => $query->whereRaw('FALSE'),
            );

        $access->applyTo($rows);

        return $rows;
    }

    private function hydrate(object $row, float $score): CuratedAnswer
    {
        return new CuratedAnswer(
            answerId: (int) $row->id,
            lessonId: (int) $row->lesson_id,
            lessonTitle: (string) $row->lesson_title,
            courseTitle: (string) $row->course_title,
            courseSlug: (string) $row->course_slug,
            question: (string) $row->question,
            answer: (string) $row->answer,
            location: $this->location($row),
            score: $score,
        );
    }

    /**
     * Место, куда ведёт строка.
     *
     * Ссылка на файл подписывается здесь же — модель её не видит и видеть не
     * должна, а читателю без неё некуда идти.
     */
    private function location(object $row): SourceLocation
    {
        $kind = AnswerSource::from((string) $row->source_kind);

        if ($kind !== AnswerSource::Attachment || $row->attachment_id === null) {
            return new SourceLocation(
                kind: $kind,
                seconds: $row->source_seconds === null ? null : (int) $row->source_seconds,
                blockId: $row->source_block_id === null ? null : (string) $row->source_block_id,
            );
        }

        $attachment = new LessonAttachment;
        $attachment->forceFill([
            'disk' => $row->attachment_disk,
            'path' => $row->attachment_path,
            'name' => $row->attachment_name,
            'mime_type' => $row->attachment_mime,
        ]);

        return new SourceLocation(
            kind: $kind,
            page: $row->source_page === null ? null : (int) $row->source_page,
            attachmentName: (string) $row->attachment_name,
            attachmentUrl: $this->signed($attachment),
        );
    }

    /**
     * Хранилище может быть недоступно, а ответ от этого хуже не становится:
     * карточка просто останется без ссылки на файл.
     */
    private function signed(LessonAttachment $attachment): ?string
    {
        try {
            return $attachment->url();
        } catch (Throwable $exception) {
            Log::warning('Ссылка на файл источника не подписана.', ['exception' => $exception]);

            return null;
        }
    }
}
