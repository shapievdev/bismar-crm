<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AnswerSource;
use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\RegulationAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the consultant is allowed to read.
 *
 * Published material only, and только то, что открыто спрашивающему. Drafts and
 * archived courses are excluded at the query — an assistant that paraphrases an
 * unpublished draft leaks it just as surely as showing the page would.
 *
 * Приватные курсы отбираются тем же условием, что и везде (CourseAccess), и по
 * той же причине: пересказ закрытого материала выдаёт его не хуже, чем открытая
 * страница. Кэш подсказки модели на этом не ломается — перечень открытых курсов
 * по-прежнему общий и кэшируется один на всех, а личная часть идёт отдельным
 * блоком за точкой кэширования; см. AnswerFromKnowledgeBase::system().
 */
final readonly class KnowledgeBase
{
    /** Перечень открытых курсов — один на всю компанию. */
    private const PUBLIC_CATALOGUE_KEY = 'ai.catalogue.public';

    /**
     * How many distinct words of the question a lesson matched.
     *
     * The signal that separates an answer from noise, and the one `ts_rank`
     * does not give: asked "что делать, если клиент говорит дорого", every
     * lesson titled "Что делать дальше" matches the single filler word
     * "делать" and scores two thirds of what a lesson matching three of them
     * scores — close enough that no threshold on the score alone can tell them
     * apart. Counted against the question's own lexemes, so stemming and
     * stopwords are handled by the same rules that built the index.
     */
    private const MATCHED_TERMS = <<<'SQL'
        cardinality(ARRAY(
            SELECT unnest(tsvector_to_array(%1$s))
            INTERSECT
            SELECT unnest(tsvector_to_array(%2$s))
        ))
    SQL;

    /** Text made searchable: a column, or `?` for the question itself. */
    private static function normalised(string $expression): string
    {
        return RussianText::normalised($expression);
    }

    /**
     * The searchable text of a passage, its lesson's title weighted above it.
     *
     * Must stay identical to the expression the GIN index was built on, or
     * Postgres cannot use the index — see the migration that creates
     * transcript_segments_search_idx.
     */
    private static function document(): string
    {
        return RussianText::document('transcript_segments.heading', 'transcript_segments.content');
    }

    /**
     * Сколько фрагментов отбирают слова, прежде чем их пересортирует смысл.
     *
     * Достаточно широко, чтобы туда попал материал, совпавший с вопросом лишь
     * одним расхожим словом, и достаточно узко, чтобы сравнение векторов
     * оставалось мгновенным: расширения pgvector в базе нет, близость считается
     * в приложении.
     */
    private const CANDIDATES = 120;

    /**
     * Смягчение при слиянии двух выдач: К в 1/(К + место).
     *
     * Число из работы, которой приём описан (Cormack и др., 2009). Чем оно
     * больше, тем меньше разница между первым местом и десятым, — и тем сильнее
     * согласие двух выдач весит против уверенности одной. Шестьдесят —
     * проверенная временем середина: первое место весит вдвое против сорок
     * первого, а не вдесятеро против десятого.
     */
    private const FUSION_SMOOTHING = 60;

    /**
     * How far below the best match a lesson may score and still be sent.
     *
     * A third, not a half: the stemmer is not consistent enough for a tighter
     * bound. Asked about "краску зимой" it produces "зим", while the lesson
     * that answers says "зимняя перевозка" and yields "зимн" — the lesson
     * matches on temperature alone and would be cut, though it is the one to
     * read. The heuristic is deliberately loose; what keeps the noise out is
     * the two filters above, not this.
     */
    private const WEAK_MATCH_SHARE = '0.34';

    /**
     * Words that make a question a question and name nothing in it.
     *
     * The Russian dictionary already drops "что", "где", "кто" and the rest of
     * the stopwords; these are what it keeps. They are not rare — but they are
     * not common enough to be caught by frequency either, and "какую краску в
     * ванную" stems "какую" to "как", which then outweighs "ванная" and buries
     * the one lesson that answers.
     *
     * The cost is "дело" as a noun, which stems to the same "дела". A question
     * that turns on it is worth less than every question that turns on filler.
     *
     * @var list<string>
     */
    private const QUESTION_WORDS = ['дела', 'как', 'нужн', 'поч', 'сдела', 'скольк', 'эт'];

    /**
     * Всё, что консультанту позволено читать, — как FROM со связями.
     *
     * Корпус двух родов: уроки опубликованных курсов и опубликованные
     * документы. Соединения левые, потому что у куска хозяин ровно один, и
     * какой именно — сказано условием ниже: пустая ветка отсеивается сама.
     *
     * Документы попали сюда позже уроков и по той же причине, по какой уроки
     * были здесь всегда: сотрудник спрашивает «как оформить возврат», а где это
     * написано — в уроке или в правилах, — знать не обязан.
     */
    private const CORPUS = <<<'SQL'
        transcript_segments
        LEFT JOIN lessons ON lessons.id = transcript_segments.lesson_id
        LEFT JOIN course_modules ON course_modules.id = lessons.module_id
        LEFT JOIN courses ON courses.id = course_modules.course_id
        LEFT JOIN regulations ON regulations.id = transcript_segments.regulation_id
    SQL;

    /**
     * Условие видимости обеих веток разом.
     *
     * Черновики и удалённое отсеиваются здесь же: пересказ неопубликованного
     * выдаёт его так же верно, как открытая страница. Закрытое — тем же
     * правилом, каким закрыты сами страницы (CourseAccess и RegulationAccess).
     */
    private function visible(CourseAccess $access, RegulationAccess $documents): string
    {
        return sprintf(<<<'SQL'
            WHERE (
                (courses.id IS NOT NULL AND courses.status = ? AND courses.deleted_at IS NULL%1$s)
                OR
                (regulations.id IS NOT NULL AND regulations.status = ? AND regulations.deleted_at IS NULL%2$s)
            )
        SQL, $access->sqlCondition(), $documents->sqlCondition());
    }

    /**
     * Подстановки к visible(), в том же порядке.
     *
     * @return list<mixed>
     */
    private function visibleBindings(CourseAccess $access, RegulationAccess $documents): array
    {
        return [
            CourseStatus::Published->value,
            ...$access->sqlBindings(),
            CourseStatus::Published->value,
            ...$documents->sqlBindings(),
        ];
    }

    /** Корпус вместе с условием видимости — то, что подставляется в FROM. */
    private function publishedMaterial(CourseAccess $access, RegulationAccess $documents): string
    {
        return self::CORPUS.$this->visible($access, $documents);
    }

    /**
     * Куски материалов, отвечающие на вопрос, — самые подходящие первыми.
     *
     * Ищут двое, независимо друг от друга и по всему корпусу.
     *
     * Слова — полнотекстовым поиском Postgres под конфигурацией `russian`,
     * которая стеммит: «возражения клиентов» находит урок про «работу с
     * возражением». Слова точны там, где вопрос и материал названы одинаково,
     * и слепы там, где по-разному.
     *
     * Смысл — расстоянием между векторами, по HNSW-индексу. Он находит
     * «Матрицу подбора по помещениям» по вопросу «как подобрать краску», где
     * общих лемм нет вовсе, и промахивается на точных названиях и числах,
     * которые вектор усредняет.
     *
     * Раньше смысл не искал, а лишь пересортировывал сто двадцать кусков,
     * отобранных словами. На нынешней базе в эти сто двадцать попадала пятая
     * часть всего корпуса, и разница была незаметна; на базе в сто раз большей
     * туда попадал бы верхний процент, и всё, что словами не совпало, стало бы
     * недостижимым. Поиск не должен слабеть от того, что базу наполняют.
     *
     * Две выдачи сводятся в одну по взаимному рангу — см. fused().
     *
     * Отдаёт больше, чем уйдёт в ответ: за лучшими кусками идут следующие по
     * счёту — те, что раньше просто не помещались в бюджет и терялись. Ими не
     * отвечают, их предлагают посмотреть.
     */
    public function search(Asked $asked, int $limit, int $relatedLimit, int $excerptChars, CourseAccess $access): Retrieved
    {
        $relatedLimit = max($relatedLimit, 0);
        $keep = $limit + $relatedLimit;

        // Документы закрыты своим правилом, но читателем — тем же самым: корпус
        // у консультанта общий, и собирать его по двум разным людям нельзя.
        $documents = RegulationAccess::of($access->reader());

        // Спрашивается у самого вопроса, а не у настроек: вектор мог и не
        // посчитаться, и тогда искать смыслом нечем.
        $semantic = $asked->isSemantic();

        $words = $this->subjectWords($asked->text, $access, $documents);

        $byWords = $words === [] ? [] : $this->matchedByWords(
            $words,
            $semantic ? self::CANDIDATES : $keep,
            $access,
            $documents,
            // Порог отсекает найденное по одному расхожему слову — он нужен,
            // пока слова решают всё. Когда рядом ищет смысл, слабое совпадение
            // и так остаётся внизу, а выбрасывать его значит терять то немногое,
            // что словами всё-таки нашлось.
            applyFloor: ! $semantic,
        );

        $byMeaning = $semantic
            ? $this->nearestByMeaning($asked, self::CANDIDATES, $access, $documents)
            : [];

        $ranked = $this->excerpts(
            array_slice($this->fused($byWords, $byMeaning), 0, $keep),
            $excerptChars,
            $access,
            $documents,
        );

        return new Retrieved(
            array_slice($ranked, 0, $limit),
            array_slice($ranked, $limit, $relatedLimit),
        );
    }

    /**
     * Уроки, чьё название и курс говорят, что они про это, — последнее средство.
     *
     * Ищется по именам, а не по расшифровкам, и потому находит там, где обычный
     * поиск бессилен: слово живёт в названии курса или в его описании и не
     * встречается ни в одной расшифровке; урок вовсе не расшифрован и до сих пор
     * для консультанта не существовал. Отвечать по названию нельзя — в нём нет
     * ответа, — но сказать «посмотрите вот это» честнее, чем «ничего нет».
     *
     * Порогов здесь нет намеренно: сюда доходят вопросы, по которым не нашлось
     * ничего вообще, и любая зацепка лучше пустоты. Индекса на эти поля тоже нет
     * — курсов и уроков на порядки меньше, чем кусков текста, а путь этот редкий.
     *
     * @return list<LessonSuggestion>
     */
    public function nearby(string $question, int $limit, CourseAccess $access): array
    {
        $question = trim($question);

        if ($question === '' || $limit < 1) {
            return [];
        }

        $lexemes = $this->lexemes($question);

        if ($lexemes === []) {
            return [];
        }

        $tsquery = $this->tsquery($lexemes, ' | ');
        $document = RussianText::document('lessons.title', "courses.title || ' ' || coalesce(courses.summary, '')");

        $rows = DB::select(sprintf(<<<'SQL'
            SELECT
                lessons.id,
                lessons.title AS lesson_title,
                courses.title AS course_title,
                courses.slug AS course_slug,
                courses.summary AS course_summary,
                ts_rank(%1$s, to_tsquery('simple', ?)) AS rank
            FROM lessons
            JOIN course_modules ON course_modules.id = lessons.module_id
            JOIN courses ON courses.id = course_modules.course_id
            WHERE courses.status = ?
              AND courses.deleted_at IS NULL
              %2$s
              AND %1$s @@ to_tsquery('simple', ?)
            ORDER BY rank DESC, lessons.id
            LIMIT ?
        SQL, $document, $access->sqlCondition()),
            [
                $tsquery,
                CourseStatus::Published->value,
                ...$access->sqlBindings(),
                $tsquery,
                $limit,
            ],
        );

        return array_map(
            static fn (object $row): LessonSuggestion => new LessonSuggestion(
                lessonId: (int) $row->id,
                lessonTitle: (string) $row->lesson_title,
                courseTitle: (string) $row->course_title,
                courseSlug: (string) $row->course_slug,
                summary: $row->course_summary === null ? '' : (string) $row->course_summary,
            ),
            $rows,
        );
    }

    /**
     * Леммы вопроса — все, какие оставил русский словарь.
     *
     * Без отсева расхожих слов и без проверки, встречается ли слово в базе: то и
     * другое решает, чем отвечать, а здесь решается лишь, что предложить взамен
     * ответа.
     *
     * @return list<string>
     */
    private function lexemes(string $question): array
    {
        $rows = DB::select(sprintf(
            'SELECT DISTINCT unnest(tsvector_to_array(to_tsvector(\'russian\', %s))) AS lexeme',
            self::normalised('?'),
        ), [$question]);

        return array_map(static fn (object $row): string => (string) $row->lexeme, $rows);
    }

    /**
     * Куски, ближайшие к вопросу по смыслу, — расстояние считает база.
     *
     * Порог обязателен, и он здесь единственный. Ближайшие восемь кусков есть
     * всегда, даже когда спрашивают про то, чего в базе нет вовсе: у слов есть
     * хотя бы «ни одно слово не совпало», а у вектора такого исхода нет — он
     * измеряет расстояние, а не совпадение. Без порога консультант потерял бы
     * два исхода из трёх: и честное «ничего нет», и совет посмотреть близкое.
     *
     * Отсечка стоит поверх обхода индекса, а не в его условии: индекс ищет
     * ближайших, и просить его о ближайших ближе чем — значит мешать ему
     * работать. Дешевле взять сколько просили и отбросить далёкое.
     *
     * @return list<int> номера кусков, ближайший первым
     */
    private function nearestByMeaning(Asked $asked, int $limit, CourseAccess $access, RegulationAccess $documents): array
    {
        $vector = Vector::literal($asked->vector);

        $rows = DB::select(sprintf(<<<'SQL'
            SELECT id FROM (
                SELECT
                    transcript_segments.id,
                    transcript_segments.embedding <=> ?::vector AS distance
                FROM %1$s
                  AND transcript_segments.embedding IS NOT NULL
                ORDER BY transcript_segments.embedding <=> ?::vector
                LIMIT ?
            ) AS nearest
            WHERE 1 - distance >= ?
            ORDER BY distance
        SQL, $this->publishedMaterial($access, $documents)), [
            $vector,
            ...$this->visibleBindings($access, $documents),
            $vector,
            $limit,
            (float) config('ai.passages_floor'),
        ]);

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * Сводит две выдачи в одну по взаимному рангу (reciprocal rank fusion).
     *
     * Каждый кусок получает по 1/(K + место) от каждой выдачи, где он нашёлся,
     * и они складываются. Сравниваются при этом места, а не оценки, — и в этом
     * весь смысл: ts_rank и косинусное расстояние живут на разных шкалах,
     * несопоставимых ни между собой, ни от вопроса к вопросу. Место
     * сопоставимо всегда.
     *
     * Найденное обоими поднимается выше найденного одним — что и требуется:
     * совпало и по словам, и по смыслу значит «про это самое».
     *
     * K = 60 — число из работы, которой приём описан; оно смягчает разницу
     * между первым и вторым местом, чтобы согласие двух выдач весило больше,
     * чем уверенность одной.
     *
     * @param  list<int>  $byWords
     * @param  list<int>  $byMeaning
     * @return list<int>
     */
    private function fused(array $byWords, array $byMeaning): array
    {
        $scores = [];

        foreach ([$byWords, $byMeaning] as $ranking) {
            foreach ($ranking as $place => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / (self::FUSION_SMOOTHING + $place + 1);
            }
        }

        arsort($scores);

        return array_map(intval(...), array_keys($scores));
    }

    /**
     * The words of the question worth searching on, with what each is worth.
     *
     * Everything the Russian dictionary keeps, minus the words so common in
     * this base that they match almost everything, each carrying a weight that
     * falls as the word gets more common. Counting words equally is what put a
     * lesson about colour mixing above the one on claims: it shared four
     * ordinary words with the question, against the two that named the
     * subject. Requiring the single rarest word is no better — in "клиент
     * принёс претензию" that is "принёс", a verb about nothing.
     *
     * @return list<array{lexeme: string, weight: float}>
     */
    private function subjectWords(string $question, CourseAccess $access, RegulationAccess $documents): array
    {
        if ($question === '') {
            return [];
        }

        // Counted with a correlated subquery per word rather than by joining
        // the corpus in: `@@` against the indexed expression is an index scan,
        // and a handful of them beats one pass that reads every lesson.
        $rows = DB::select(sprintf(<<<'SQL'
            WITH asked AS (
                SELECT DISTINCT unnest(tsvector_to_array(to_tsvector('russian', %2$s))) AS lexeme
            )
            SELECT
                asked.lexeme,
                (SELECT count(*) FROM %3$s AND %1$s @@ to_tsquery('simple', quote_literal(asked.lexeme))) AS found_in,
                (SELECT count(*) FROM %3$s) AS pieces
            FROM asked
            ORDER BY found_in, asked.lexeme
        SQL,
            self::document(),
            self::normalised('?'),
            $this->publishedMaterial($access, $documents),
        ), [
            $question,
            // По одному набору на каждое вхождение корпуса: он стоит в запросе
            // дважды, и подстановки к нему повторяются вслед за ним.
            ...$this->visibleBindings($access, $documents),
            ...$this->visibleBindings($access, $documents),
        ]);

        if ($rows === []) {
            return [];
        }

        $pieces = (int) $rows[0]->pieces;

        // Words that name something and occur at least once. A word the base
        // never uses cannot bring back a lesson, and keeping it would only let
        // it win the "rarest" tie-break below and search for nothing.
        $known = array_values(array_filter(
            $rows,
            static fn (object $row): bool => (int) $row->found_in > 0,
        ));

        $candidates = array_values(array_filter(
            $known,
            static fn (object $row): bool => ! in_array($row->lexeme, self::QUESTION_WORDS, strict: true),
        ));

        // Вопрос, в котором нет ничего, кроме расхожих слов: «как это сделать»,
        // «сколько нужно». Отсев их не для того, чтобы такой вопрос остался без
        // поиска вовсе, а чтобы они не перевешивали слова о предмете — когда
        // перевешивать нечего, лучше искать по ним, чем не искать ничего.
        if ($candidates === []) {
            $candidates = $known;
        }

        if ($candidates === []) {
            return [];
        }

        // Расхожие слова остаются, но почти ничего не весят. Выбрасывать их
        // совсем было ошибкой: в вопросе «как подобрать краску» после этого не
        // оставалось ни одного слова, по которому можно найти материал про
        // краску — «подобрать» и «подбор» стеммер разводит в разные стороны.
        $informative = $candidates;

        return array_map(
            static fn (object $row): array => [
                'lexeme' => (string) $row->lexeme,
                // Rarity, on the usual logarithmic scale: a word in one lesson
                // out of a hundred is worth several that are in a third of
                // them. A word in no lesson at all cannot raise anyone's score,
                // so its weight only has to be finite.
                'weight' => log(max($pieces, 1) / max((int) $row->found_in, 1)) + 1,
            ],
            $informative,
        );
    }

    /**
     * A tsquery over already-stemmed lexemes.
     *
     * Built under the `simple` configuration on purpose: these words came out
     * of the Russian dictionary already, and running them through it a second
     * time can stem a stem into something the index does not contain.
     *
     * @param  list<string>  $lexemes
     */
    private function tsquery(array $lexemes, string $operator = ' & '): string
    {
        return implode($operator, array_map(
            static fn (string $lexeme): string => "'".str_replace("'", "''", $lexeme)."'",
            $lexemes,
        ));
    }

    /**
     * A Postgres array literal, passed as one bound string rather than built
     * into the statement.
     *
     * @param  list<string>  $values
     */
    private function arrayLiteral(array $values): string
    {
        return '{'.implode(',', array_map(
            static fn (string $value): string => '"'.addcslashes($value, '"\\').'"',
            $values,
        )).'}';
    }

    /**
     * Куски, совпавшие с вопросом словами, — самые весомые первыми.
     *
     * @param  list<array{lexeme: string, weight: float}>  $words
     * @return list<int> номера кусков
     */
    private function matchedByWords(
        array $words,
        int $limit,
        CourseAccess $access,
        RegulationAccess $documents,
        bool $applyFloor = true,
    ): array {
        $lexemes = array_column($words, 'lexeme');
        $tsquery = $this->tsquery($lexemes, ' | ');

        $rows = DB::select(sprintf(<<<'SQL'
            WITH ranked AS (
                SELECT
                    transcript_segments.id,
                    ts_rank(%1$s, to_tsquery('simple', ?)) AS rank,
                    (
                        SELECT coalesce(sum(asked.weight), 0)
                        FROM unnest(?::text[], ?::float8[]) AS asked(lexeme, weight)
                        WHERE asked.lexeme = ANY (tsvector_to_array(%1$s))
                    ) AS score
                FROM %3$s
                  AND %1$s @@ to_tsquery('simple', ?)
            )
            SELECT id FROM ranked
            -- A third of what the best match scored. A passage below that
            -- shares only the ordinary words of the question, not its subject.
            WHERE score >= (SELECT max(score) FROM ranked) * %2$s
            ORDER BY score DESC, rank DESC, id
            LIMIT ?
        SQL, self::document(), $applyFloor ? self::WEAK_MATCH_SHARE : '0', $this->publishedMaterial($access, $documents)),
            // Positional, and order-sensitive: PDO will not take one named
            // placeholder for the several places the same value appears in.
            [
                $tsquery,
                $this->arrayLiteral($lexemes),
                $this->arrayLiteral(array_map(
                    static fn (float $weight): string => sprintf('%.6F', $weight),
                    array_column($words, 'weight'),
                )),
                ...$this->visibleBindings($access, $documents),
                $tsquery,
                $limit,
            ],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * Отобранные куски целиком — с текстом, названием материала и местом.
     *
     * Отдельным запросом, а не вместе с отбором: обе выдачи отбирают по сотне
     * кандидатов, а до ответа доходит десяток, и вычитывать текст сотни кусков
     * ради того, чтобы выбросить девять десятых, незачем.
     *
     * Условие видимости повторяется и здесь, хотя номера пришли из запросов, где
     * оно уже стояло. Это не лишняя осторожность, а граница: пока она в самом
     * запросе, никакой будущий способ отбора не сможет протащить сюда закрытый
     * материал, забыв её применить.
     *
     * @param  list<int>  $ids
     * @return list<Excerpt> в том же порядке, в каком пришли номера
     */
    private function excerpts(array $ids, int $excerptChars, CourseAccess $access, RegulationAccess $documents): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::select(sprintf(<<<'SQL'
            SELECT
                lessons.id AS lesson_id,
                transcript_segments.id AS segment_id,
                lessons.title AS lesson_title,
                transcript_segments.content,
                courses.title AS course_title,
                courses.slug AS course_slug,
                -- Документ вместо урока: у куска заполнено одно из двух.
                regulations.id AS document_id,
                regulations.title AS document_title,
                regulations.slug AS document_slug,
                regulations.kind AS document_kind,
                -- Место, откуда взят кусок: секунда записи, лист документа,
                -- абзац статьи. Ради него расшифровки и заведены — ссылка
                -- на урок целиком означала бы «ищите сами».
                transcript_segments.starts_at_seconds,
                transcript_segments.page,
                transcripts.source_kind,
                -- Абзац помнит кусок: расшифровка статьи одна на урок, и
                -- сказать, к какому месту относится найденное, может только
                -- он. У расшифровки поле остаётся ради загруженных вручную.
                coalesce(transcript_segments.source_block_id, transcripts.source_block_id) AS source_block_id,
                attachments.name AS attachment_name
            FROM transcript_segments
            JOIN lesson_transcripts AS transcripts ON transcripts.id = transcript_segments.transcript_id
            LEFT JOIN lesson_attachments AS attachments ON attachments.id = transcripts.source_attachment_id
            LEFT JOIN lessons ON lessons.id = transcript_segments.lesson_id
            LEFT JOIN course_modules ON course_modules.id = lessons.module_id
            LEFT JOIN courses ON courses.id = course_modules.course_id
            LEFT JOIN regulations ON regulations.id = transcript_segments.regulation_id
            %1$s
              AND transcript_segments.id = ANY (?::bigint[])
        SQL, $this->visible($access, $documents)), [
            ...$this->visibleBindings($access, $documents),
            '{'.implode(',', $ids).'}',
        ]);

        $found = [];

        foreach ($rows as $row) {
            $found[(int) $row->segment_id] = $this->excerpt($row, $excerptChars);
        }

        // Порядок задают номера, а не база: он сложен слиянием двух выдач и в
        // SQL не выражается.
        return array_values(array_filter(array_map(
            static fn (int $id): ?Excerpt => $found[$id] ?? null,
            $ids,
        )));
    }

    /**
     * Найденный кусок как источник: из урока или из документа.
     *
     * Различает их только эта строчка. Дальше, где ответ собирается и где
     * сверяются ссылки, важно лишь то, что источник умеет назвать себя.
     */
    private function excerpt(object $row, int $excerptChars): Excerpt
    {
        $location = new SourceLocation(
            kind: AnswerSource::from((string) $row->source_kind),
            seconds: $row->starts_at_seconds === null ? null : (int) $row->starts_at_seconds,
            page: $row->page === null ? null : (int) $row->page,
            blockId: $row->source_block_id === null ? null : (string) $row->source_block_id,
            attachmentName: $row->attachment_name === null ? null : (string) $row->attachment_name,
        );

        $text = $this->trimToWord((string) $row->content, $excerptChars);

        if ($row->document_id !== null) {
            return new DocumentExcerpt(
                documentId: (int) $row->document_id,
                segmentId: (int) $row->segment_id,
                title: (string) $row->document_title,
                slug: (string) $row->document_slug,
                kind: MaterialKind::tryFrom((string) $row->document_kind) ?? MaterialKind::Document,
                text: $text,
                location: $location,
            );
        }

        return new LessonExcerpt(
            lessonId: (int) $row->lesson_id,
            segmentId: (int) $row->segment_id,
            lessonTitle: (string) $row->lesson_title,
            courseTitle: (string) $row->course_title,
            courseSlug: (string) $row->course_slug,
            text: $text,
            location: $location,
        );
    }

    /**
     * Every published course open to the whole company, one line each.
     *
     * Sent ahead of the question so the model knows the shape of the whole base
     * rather than only what the search happened to return — which is what lets
     * it answer "there is nothing on that here" with confidence instead of
     * inventing something plausible.
     *
     * Один на всех и потому кэшируемый — и здесь, и на стороне модели.
     */
    public function publicCatalogue(): string
    {
        return Cache::remember(
            self::PUBLIC_CATALOGUE_KEY,
            now()->addMinutes((int) config('ai.catalogue_cache_minutes')),
            function (): string {
                $courses = $this->lines(
                    $this->publishedCourses()->where('courses.visibility', CourseVisibility::Public->value),
                );

                // Документы отдельным перечнем, а не вперемешку с курсами:
                // модель должна понимать, что перед ней правило, а не учебный
                // материал, — от этого зависит и тон ответа, и то, насколько
                // буквально его следует пересказывать.
                $documents = $this->lines(
                    $this->publishedDocuments()->where('regulations.visibility', CourseVisibility::Public->value),
                );

                return implode("\n", array_filter([
                    $courses === '' ? '' : "Курсы:\n".$courses,
                    $documents === '' ? '' : "Документы — правила, по которым работают:\n".$documents,
                ]));
            },
        );
    }

    /**
     * Приватные курсы, открытые этому человеку, — тем же перечнем.
     *
     * Пусто, если таких нет, а это подавляющее большинство спрашивающих.
     * Кэшируется по составу доступного, а не по человеку: у всех, кому открыто
     * одно и то же, перечень один.
     */
    public function privateCatalogue(CourseAccess $access): string
    {
        $ids = $access->privateCourseIds();

        if ($ids === []) {
            return '';
        }

        return Cache::remember(
            'ai.catalogue.private.'.$access->fingerprint(),
            now()->addMinutes((int) config('ai.catalogue_cache_minutes')),
            fn (): string => $this->lines($this->publishedCourses()->whereIn('courses.id', $ids)),
        );
    }

    /**
     * Забывает перечень открытых курсов.
     *
     * Зовётся, когда курс закрывают или открывают: пока перечень лежит в кэше,
     * модели показывают название курса, который уже стал приватным.
     */
    public static function forgetPublicCatalogue(): void
    {
        Cache::forget(self::PUBLIC_CATALOGUE_KEY);
    }

    private function publishedCourses(): Builder
    {
        return DB::table('courses')
            ->select('courses.title', 'courses.summary', 'categories.name as category')
            ->leftJoin('categories', 'categories.id', '=', 'courses.category_id')
            ->where('courses.status', CourseStatus::Published->value)
            ->whereNull('courses.deleted_at')
            ->orderByRaw('courses.title COLLATE "und-x-icu"');
    }

    private function publishedDocuments(): Builder
    {
        return DB::table('regulations')
            ->select('regulations.title', 'regulations.summary', 'regulation_categories.name as category')
            ->leftJoin('regulation_categories', 'regulation_categories.id', '=', 'regulations.category_id')
            ->where('regulations.status', CourseStatus::Published->value)
            ->whereNull('regulations.deleted_at')
            ->orderByRaw('regulations.title COLLATE "und-x-icu"');
    }

    private function lines(Builder $query): string
    {
        // The title is quoted and everything else is pushed behind a dash, so
        // that the name is a self-contained unit. Written as `Название
        // [Категория]` the model copies the bracket into the answer and invents
        // a course nobody named that.
        return $query->get()->map(static function (object $row): string {
            $line = '- «'.$row->title.'»';

            if ($row->category !== null) {
                $line .= ' — раздел: '.$row->category;
            }

            return $row->summary === null ? $line : $line.'. '.$row->summary;
        })->implode("\n");
    }

    /**
     * Cuts at a word boundary so an excerpt never ends mid-word — a truncated
     * term reads to the model as a different word than the one that was there.
     */
    private function trimToWord(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace)).'…';
    }
}
