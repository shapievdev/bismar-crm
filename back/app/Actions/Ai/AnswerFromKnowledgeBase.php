<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use Anthropic\Client;
use App\Enums\AnswerPath;
use App\Enums\CourseVisibility;
use App\Models\User;
use App\Support\Ai\Citation;
use App\Support\Ai\Conversation;
use App\Support\Ai\CourseExpert;
use App\Support\Ai\CuratedAnswers;
use App\Support\Ai\KnowledgeBase;
use App\Support\Ai\ModelSettings;
use App\Support\Ai\Retrieved;
use App\Support\Ai\Source;
use App\Support\Lms\CourseAccess;
use Illuminate\Support\Facades\DB;

/**
 * Answers a question using the company's own course material and nothing else.
 *
 * Ищет в два захода. Сперва по таблицам уроков — там, где автор сам написал,
 * какой вопрос разбирается и каков ответ; такой ответ выверен человеком и
 * потому всегда важнее того, что удалось выхватить из текста. И только если
 * в таблицах ничего нет — по нарезке текста, как было до них.
 *
 * Найденное делится на то, чем отвечают, и то, что предлагают рядом. Прежде
 * второго не существовало: не дотянувшее до порога поиск выбрасывал, и на
 * вопрос, разобранный в базе чуть иначе, сотрудник получал «ничего нет» — при
 * том что материал по соседству лежал. Теперь у консультанта три исхода вместо
 * двух: ответить, ответа не найти но показать близкое, и — только если нет и
 * близкого — сказать, что материала нет.
 *
 * Отвечает не «по базе знаний», а по той её части, что открыта спрашивающему:
 * приватный курс отвечает только своим. Область чтения считается один раз на
 * вопрос и передаётся во все поиски — разойтись им нельзя.
 */
final readonly class AnswerFromKnowledgeBase
{
    /**
     * The answer to a question with no matching material.
     *
     * Returned without calling the model at all: there is nothing for it to
     * ground an answer in, and asking it anyway is exactly the situation where
     * a model invents a plausible-sounding regulation.
     *
     * Досюда доходят только вопросы, по которым не нашлось ни ответа, ни
     * близкого, ни даже урока с подходящим названием.
     */
    private const NOTHING_FOUND = 'В базе знаний об этом ничего нет.';

    public function __construct(
        private Client $client,
        private KnowledgeBase $knowledge,
        private CuratedAnswers $curated,
        private RestateQuestion $restate,
        private ModelSettings $settings,
    ) {}

    public function handle(string $question, User $reader): Answer
    {
        $access = CourseAccess::of($reader);
        $relatedLimit = (int) config('ai.related_per_reply');

        // Разговор нужен раньше поиска, а не только при сборке ответа: «а
        // сколько это сохнет?» ищется впустую, пока в нём не окажется слов о
        // предмете. Дополняется то, по чему ищут, — сотруднику и в журнал идут
        // его собственные слова.
        $conversation = Conversation::of($reader, (int) config('ai.conversation_turns'));
        $restated = $this->restate->handle($question, $conversation);
        $asked = $restated ?? $question;

        $curated = $this->curated->search(
            $asked,
            (int) config('ai.answers_per_reply'),
            $relatedLimit,
            $access,
        );

        // Вопрос совпал со строкой настолько точно, что пересказывать нечего:
        // ответ автора отдаётся как есть, модель не вызывается вовсе. Это и
        // быстрее, и бесплатно, и переврать выверенную формулировку тут нечему.
        // Поиск по тексту при этом не запускается: платить за него нечем — он
        // ничего не добавит к ответу, а близкое уже нашлось по дороге.
        if ($curated->exact !== [] && $this->curated->isVerbatim($curated->exact)) {
            $best = $curated->exact[0];

            return new Answer(
                $best->answer,
                [$best],
                count($curated->exact),
                AnswerPath::Curated,
                verbatim: true,
                related: $curated->related,
                privateCourseIds: $this->privateCoursesAmong($curated->all()),
                privateDocumentIds: $this->privateDocumentsAmong($curated->all()),
                searchedAs: $restated,
            );
        }

        $passages = $this->knowledge->search(
            $asked,
            (int) config('ai.lessons_per_answer'),
            $relatedLimit,
            (int) config('ai.lesson_excerpt_chars'),
            $access,
        );

        // Отвечают таблицы, если им есть чем; текст урока при этом не пропадает,
        // а становится тем, что читателю предложат посмотреть. Прежде он в этом
        // случае не искался вовсе, и разбор той же темы в самом уроке оставался
        // сотруднику неизвестен.
        $answersFromTables = $curated->exact !== [];

        $found = $answersFromTables
            ? $curated->plusRelated($passages->all(), $relatedLimit)
            : (new Retrieved($passages->exact, $curated->related))
                ->plusRelated($passages->related, $relatedLimit);

        // Последнее средство: урок, о котором известно одно название. Обычный
        // поиск идёт по расшифровкам и не видит слова, живущего в имени курса,
        // — как не видит и урока, который ещё не расшифрован вовсе.
        if ($found->isEmpty()) {
            $found = $found->plusRelated($this->knowledge->nearby($asked, $relatedLimit, $access), $relatedLimit);
        }

        if ($found->isEmpty()) {
            return new Answer(self::NOTHING_FOUND, [], searchedAs: $restated);
        }

        // Отвечать нечем, но есть что показать: модель зовут, чтобы она честно
        // это и сказала — своими словами про то, о чём найденное, и без попытки
        // выдать соседнюю тему за ответ.
        $path = match (true) {
            $found->exact === [] => AnswerPath::Related,
            $answersFromTables => AnswerPath::Curated,
            default => AnswerPath::Passages,
        };

        return $this->compose($question, $found, $path, $access, $conversation, $restated);
    }

    /**
     * Ответ, собранный моделью по найденным источникам.
     *
     * Виду источника здесь всё равно: строка таблицы, кусок текста и совет
     * посмотреть урок одинаково умеют назвать себя, и различать их дальше
     * незачем.
     *
     * Модели уходит либо точное, либо — когда точного нет — близкое. Вместе они
     * не уходят никогда: получив рядом с верным фрагментом три соседних, слабая
     * модель отвечает по тому, что ей приглянулось. Та же причина, по которой
     * заведён ai.answers_relative_floor.
     */
    private function compose(
        string $question,
        Retrieved $found,
        AnswerPath $path,
        CourseAccess $access,
        Conversation $conversation,
        ?string $restated,
    ): Answer {
        $sources = $found->forPrompt();

        $message = $this->client->messages->create(
            model: $this->settings->model(),
            maxTokens: $this->settings->maxTokens(),
            system: $this->system($access),
            // Разговор настоящими репликами, а свежий вопрос — последней из
            // них: так модель видит, что уже сказала, и не пересказывает это
            // заново на каждое «а если».
            messages: [
                ...$conversation->messages(),
                ['role' => 'user', 'content' => $this->userTurn($question, $sources, $path)],
            ],
        );

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $text = trim($text);

        // Считается по всему найденному, а не по процитированному: пересказать
        // закрытый материал модель может и не сославшись на него, а показанное
        // карточкой «смотрите также» выдаёт курс не хуже пересказа.
        $private = $this->privateCoursesAmong($found->all());
        $privateDocuments = $this->privateDocumentsAmong($found->all());

        if ($text === '') {
            return new Answer(
                self::NOTHING_FOUND,
                [],
                count($sources),
                $path,
                related: $found->related,
                experts: $this->expertsFor($found),
                privateCourseIds: $private,
                privateDocumentIds: $privateDocuments,
                searchedAs: $restated,
            );
        }

        return $this->withCitations($text, $found, $path, $private, $privateDocuments, $restated);
    }

    /**
     * Номера уроков среди источников — без документов: урока у них нет вовсе.
     *
     * @param  list<Source>  $sources
     * @return list<int>
     */
    private static function lessonIdsAmong(array $sources): array
    {
        $lessons = array_filter(
            $sources,
            static fn (Source $source): bool => $source->citation()->kind === Citation::LESSON,
        );

        return array_values(array_unique(array_map(
            static fn (Source $source): int => $source->citation()->materialId,
            $lessons,
        )));
    }

    /**
     * Приватные документы, участвовавшие в ответе, — по той же причине, что и
     * курсы: журнал читает автор материала, а не спрашивавший.
     *
     * @param  list<Source>  $sources
     * @return list<int>
     */
    private function privateDocumentsAmong(array $sources): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (Source $source): int => $source->citation()->materialId,
            array_filter(
                $sources,
                static fn (Source $source): bool => $source->citation()->kind === Citation::DOCUMENT,
            ),
        )));

        if ($ids === []) {
            return [];
        }

        return DB::table('regulations')
            ->whereIn('id', $ids)
            ->where('visibility', CourseVisibility::Private->value)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Приватные курсы, материал которых участвовал в ответе.
     *
     * Нужны журналу: вопрос в нём читает автор материала, а не тот, кто
     * спрашивал, и показывать ему ответ, собранный из чужого закрытого курса,
     * значит выдать этот курс тем же пересказом, от которого его закрывали.
     *
     * @param  list<Source>  $sources
     * @return list<int>
     */
    private function privateCoursesAmong(array $sources): array
    {
        $lessonIds = self::lessonIdsAmong($sources);

        if ($lessonIds === []) {
            return [];
        }

        return DB::table('lessons')
            ->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->join('courses', 'courses.id', '=', 'course_modules.course_id')
            ->whereIn('lessons.id', $lessonIds)
            ->where('courses.visibility', CourseVisibility::Private->value)
            ->distinct()
            ->pluck('courses.id')
            ->map(intval(...))
            ->all();
    }

    /**
     * The instructions, and the catalogue of what exists.
     *
     * Both are identical for every reader and every question, which is what
     * makes them cacheable — and the breakpoint sits at the end of this block,
     * so the question and its excerpts stay outside the cached prefix.
     *
     * @return list<array<string, mixed>>
     */
    private function system(CourseAccess $access): array
    {
        $rules = <<<'TEXT'
        Ты отвечаешь сотрудникам компании по её базе знаний.
        Тебе дают пронумерованные фрагменты материалов и вопрос сотрудника.

        Фрагменты приходят под одним из двух заголовков.
        «ФРАГМЕНТЫ МАТЕРИАЛОВ» — найденное по вопросу, по ним и отвечают.
        «БЛИЗКОЕ ПО ТЕМЕ» — материал по соседству: прямого ответа в нём нет, но он рядом.

        Выше может лежать ваш прошлый разговор. Он для того, чтобы понять, о чём спрашивают
        сейчас: «а сколько это сохнет?» — про то, о чём шла речь. Сказанное в нём — твои
        собственные прошлые слова, повторять их целиком не нужно. Но новое утверждение всё
        равно бери из фрагментов, а не из прошлого ответа.

        Делай по порядку:

        1. Посмотри, о чём спрашивают, и найди фрагмент об этом же.
           Сотрудник пишет своими словами, а не словами материала. «Насос для скважины» и
           «насосная станция на базе скважинного насоса» — одно и то же. «Оплата» и «платёж» —
           одно и то же. Другое слово в вопросе помехой не является.
        2. Нашёл — отвечай по этому фрагменту. Сразу по делу, без вступлений.
        3. В конце каждого утверждения ставь номер фрагмента: [источник 1].
           Если фрагментов несколько: [источник 1][источник 2].
        4. Во фрагменте есть строки «Вопрос:» и «Ответ:» — приведи этот ответ как есть,
           своими словами не переписывай.
        5. Отвечать нечем, а пришло «БЛИЗКОЕ ПО ТЕМЕ» — начни словами «Прямого ответа на это
           в материалах нет.», потом одной-двумя фразами скажи, что есть рядом и о чём оно,
           со ссылкой [источник N] на каждое названное. Близкое за ответ не выдавай и не
           досказывай за него: если известно только название урока — так и назови урок.
        6. Не пришло ни того ни другого — напиши: В материалах базы знаний об этом ничего нет.
           Если в перечне внизу есть курс на эту тему, добавь: Тема разбирается в курсе «Название».

        Примеры.

        Вопрос: как выбрать насос для скважины?
        [источник 1] Вопрос: Как выбрать насосную станцию для скважины? Ответ: Если у вас скважина,
        лучший выбор — станция на базе скважинного насоса, подбирают по глубине.
        Твой ответ: Если у вас скважина, лучший выбор — станция на базе скважинного насоса,
        подбирают по глубине [источник 1].

        Вопрос: сколько ждать после второго слоя?
        [источник 1] Второй слой сохнет не менее 4 часов при 20 °C.
        Твой ответ: Не менее 4 часов при 20 °C [источник 1].

        Вопрос: как оформить возврат бракованной краски?
        БЛИЗКОЕ ПО ТЕМЕ
        [источник 1] Курс «Работа с претензиями» → урок «Приём претензии»: претензию принимают
        в течение 14 дней с даты покупки, чек обязателен.
        [источник 2] Курс «Работа с претензиями» → урок «Брак на складе». Содержание урока
        не приведено, известно только название.
        Твой ответ: Прямого ответа на это в материалах нет. Рядом есть про приём претензии —
        сроки и что нужно от покупателя [источник 1], и урок про брак на складе [источник 2].

        Вопрос: как поменять картридж в принтере?
        [источник 1] Второй слой сохнет не менее 4 часов при 20 °C.
        Твой ответ: В материалах базы знаний об этом ничего нет.

        Нельзя:
        — писать то, чего во фрагментах нет, даже если знаешь ответ;
        — пересказывать близкое так, будто это ответ на заданный вопрос;
        — употреблять слова «фрагмент», «контекст», «мне передали», «нет доступа»: сотрудник
          не знает, что тебе что-то передавали, для него есть материалы базы знаний;
        — приводить ссылки, адреса сайтов, номера документов и имена файлов, которых нет
          во фрагментах.

        Ниже — перечень всех опубликованных материалов, чтобы ты знал, что в базе есть вообще.
        TEXT;

        $blocks = [
            [
                'type' => 'text',
                'text' => $rules."\n\nМАТЕРИАЛЫ БАЗЫ ЗНАНИЙ:\n".$this->knowledge->publicCatalogue(),
                'cacheControl' => ['type' => 'ephemeral'],
            ],
        ];

        $private = $this->knowledge->privateCatalogue($access);

        if ($private !== '') {
            // Отдельным блоком и после точки кэширования. Перечень открытых
            // курсов одинаков у всех и потому кэшируется моделью один на всю
            // компанию; приклей к нему личную часть — и общего префикса не
            // осталось бы ни у кого, включая тех, у кого приватных курсов нет.
            $blocks[] = [
                'type' => 'text',
                'text' => "МАТЕРИАЛЫ, ОТКРЫТЫЕ ЛИЧНО ЭТОМУ СОТРУДНИКУ:\n".$private,
            ];
        }

        return $blocks;
    }

    /**
     * Заголовок над фрагментами говорит модели, чем они ей приходятся.
     *
     * Без него близкое неотличимо от найденного, и правило «не выдавай соседнюю
     * тему за ответ» не к чему применить: модель видит фрагмент про претензии и
     * отвечает по нему на вопрос про возврат.
     *
     * @param  list<Source>  $sources
     */
    private function userTurn(string $question, array $sources, AnswerPath $path): string
    {
        $fragments = implode(
            "\n\n",
            array_map(
                static fn (int $index, Source $source): string => $source->toPrompt($index + 1),
                array_keys($sources),
                $sources,
            ),
        );

        $heading = $path === AnswerPath::Related ? 'БЛИЗКОЕ ПО ТЕМЕ' : 'ФРАГМЕНТЫ МАТЕРИАЛОВ';

        return "{$heading}:\n\n{$fragments}\n\nВОПРОС СОТРУДНИКА:\n{$question}";
    }

    /**
     * The answer with its citations reduced to the sources it really used.
     *
     * Citations are read back out of the text rather than taken from what was
     * sent, so a reference to material that was never supplied cannot reach the
     * reader: it points at nothing, and is removed along with its marker.
     *
     * What survives is renumbered from one, in the order it first appears, so
     * that the numbers in the text and the list underneath it agree — the model
     * cites the fragments it was given, and the reader is shown a footnote list
     * that starts at 1 and has no gaps.
     *
     * @param  list<int>  $privateCourseIds
     * @param  list<int>  $privateDocumentIds
     */
    private function withCitations(
        string $answer,
        Retrieved $found,
        AnswerPath $path,
        array $privateCourseIds,
        array $privateDocumentIds,
        ?string $restated,
    ): Answer {
        $sources = $found->forPrompt();
        $cited = [];

        // Everything inside the brackets is read, not just a single number:
        // asked for one source per marker the model still writes «[источник 1,
        // 2, 3]» and «[источник 2, источник 5]», and a marker left unparsed
        // stays in the answer as literal text.
        $answer = preg_replace_callback(
            '/\[источник[^]]*]/u',
            static function (array $match) use ($sources, &$cited): string {
                preg_match_all('/\d+/', $match[0], $numbers);

                $markers = [];

                foreach ($numbers[0] as $number) {
                    $source = $sources[((int) $number) - 1] ?? null;

                    if ($source === null) {
                        continue;
                    }

                    // Ключ — источник, а не урок. Схлопывание по уроку теряло
                    // то, ради чего ссылка и нужна: два куска одного урока
                    // становились одним источником, и под утверждением стояла
                    // цитата из другого места того же текста.
                    $position = array_search($source->key(), $cited, strict: true);

                    if ($position === false) {
                        $cited[] = $source->key();
                        $position = array_key_last($cited);
                    }

                    $markers[$position] = sprintf('[источник %d]', $position + 1);
                }

                ksort($markers);

                return implode('', $markers);
            },
            $answer,
        ) ?? $answer;

        // Ordered by first mention, not by rank: the numbers in the text are
        // positions in this list, and sorting it any other way would misnumber
        // every citation after the first.
        $byKey = array_column(
            array_map(static fn (Source $source): array => [$source->key(), $source], $sources),
            1,
            0,
        );

        $used = array_values(array_map(
            static fn (string $key): Source => $byKey[$key],
            $cited,
        ));

        // Dropping a marker can leave a double space or a space before a full
        // stop where the citation used to be.
        $answer = trim((string) preg_replace(['/[ \t]{2,}/u', '/\s+([.,;:!?])/u'], [' ', '$1'], $answer));

        // Ответа по существу не вышло: либо материал оказался лишь соседним,
        // либо модель не сослалась ни на что. Это и есть тот случай, ради
        // которого за курсом закреплены живые люди.
        $experts = $path === AnswerPath::Related || $used === []
            ? $this->expertsFor($found)
            : [];

        return new Answer(
            $answer === '' ? self::NOTHING_FOUND : $answer,
            $used,
            count($sources),
            $path,
            // Процитированное уже показано читателю под ответом и снабжено
            // номером; ему же место во втором списке — «смотрите также» на то,
            // что он только что прочёл.
            related: $found->withoutCited($used)->related,
            experts: $experts,
            privateCourseIds: $privateCourseIds,
            privateDocumentIds: $privateDocumentIds,
            searchedAs: $restated,
        );
    }

    /**
     * Ответственные за курсы, материал которых участвовал в ответе.
     *
     * По всему найденному, а не по процитированному: когда цитировать нечего,
     * подсказать всё равно есть кого — материал по теме нашёлся, просто ответа
     * в нём не оказалось.
     *
     * Порядок — как у найденного: курс лучшего совпадения первым. Курсы без
     * ответственных выпадают сами, и это норма — их большинство.
     *
     * @return list<CourseExpert>
     */
    private function expertsFor(Retrieved $found): array
    {
        $lessonIds = self::lessonIdsAmong($found->all());

        if ($lessonIds === []) {
            return [];
        }

        $rows = DB::table('lessons')
            ->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->join('courses', 'courses.id', '=', 'course_modules.course_id')
            ->join('course_experts', 'course_experts.course_id', '=', 'courses.id')
            ->join('users', 'users.id', '=', 'course_experts.user_id')
            ->whereIn('lessons.id', $lessonIds)
            // Уволенного в ответе не называют: карточка «спросите
            // ответственного» ведёт в мессенджер, а туда он уже не зайдёт.
            ->whereNull('users.dismissed_at')
            ->select([
                'lessons.id as lesson_id',
                'users.id as user_id',
                'users.last_name',
                'users.first_name',
                'users.middle_name',
                'users.email',
                'users.avatar_path',
                'courses.title as course_title',
                'courses.slug as course_slug',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Тем же порядком, в каком материал попал в ответ: первым отвечает тот,
        // чей курс подошёл лучше.
        $ranks = array_flip($lessonIds);
        $experts = [];

        foreach ($rows->sortBy(fn (object $row): int => $ranks[$row->lesson_id] ?? PHP_INT_MAX) as $row) {
            if (isset($experts[$row->user_id])) {
                continue;
            }

            $person = new User;
            $person->forceFill([
                'id' => $row->user_id,
                'last_name' => $row->last_name,
                'first_name' => $row->first_name,
                'middle_name' => $row->middle_name,
                'avatar_path' => $row->avatar_path,
            ]);

            $experts[$row->user_id] = new CourseExpert(
                userId: (int) $row->user_id,
                name: $person->name,
                email: (string) $row->email,
                avatarUrl: $person->avatarUrl(),
                courseTitle: (string) $row->course_title,
                courseSlug: (string) $row->course_slug,
            );
        }

        return array_slice(array_values($experts), 0, (int) config('ai.experts_per_reply'));
    }
}
