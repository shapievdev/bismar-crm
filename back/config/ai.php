<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | Haiku is chosen deliberately: the consultant reads a handful of retrieved
    | excerpts and answers from them, which is a task the fastest and cheapest
    | model handles well. The work that decides answer quality here is the
    | retrieval, not the model tier — spending more per question would buy
    | little. Raise it if answers start missing the point of the material.
    |
    */

    'model' => env('AI_MODEL', 'claude-haiku-4-5'),

    /*
     * Модель смыслового поиска. Пусто — поиск остаётся словесным: он работает,
     * но не связывает «как подобрать краску» с «Матрицей подбора», где нет ни
     * одного общего слова.
     */
    'embedding_model' => env('AI_EMBEDDING_MODEL'),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | How many lessons the search feeds the model, and how much of each. The
    | whole knowledge base will not fit in any context window once it grows to
    | hundreds of courses, so the model reads an excerpt of what matched rather
    | than everything that exists.
    |
    | These two numbers are the cost dial: excerpts dominate the bill, and the
    | bill does not grow with the size of the base.
    |
    */

    'lessons_per_answer' => (int) env('AI_LESSONS_PER_ANSWER', 8),

    'lesson_excerpt_chars' => (int) env('AI_LESSON_EXCERPT_CHARS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Таблицы уроков
    |--------------------------------------------------------------------------
    |
    | Курируемые строки «вопрос — ответ — источник» — то, по чему консультант
    | ищет прежде всего. Они короткие, поэтому их в промпт помещается больше,
    | чем кусков текста, и стоят они дешевле.
    |
    */

    'answers_per_reply' => (int) env('AI_ANSWERS_PER_REPLY', 8),

    /*
    |--------------------------------------------------------------------------
    | Память разговора
    |--------------------------------------------------------------------------
    |
    | Сколько прошлых кругов «вопрос — ответ» консультант помнит у каждого
    | сотрудника. Без них каждый вопрос читался как первый, и через сообщение
    | после верного ответа «а сколько это сохнет?» уже ничего не значило.
    |
    | Цена — токены на каждом вопросе: разговор уходит модели целиком, и двадцать
    | кругов это несколько тысяч токенов сверху. Дальше растить смысла мало —
    | местоимение указывает на сказанное только что, а не полчаса назад.
    |
    | Своя память у каждого: вопросы одного не достраивают вопросы другого.
    |
    */

    'conversation_turns' => (int) env('AI_CONVERSATION_TURNS', 20),

    /*
     * Предел ответа модели, когда она достраивает вопрос разговором.
     *
     * От неё нужна одна строка — сам вопрос. Больше сотни токенов на неё уходит
     * только тогда, когда модель вместо вопроса принимается объяснять, и
     * обрывать её на этом дешевле, чем платить за объяснение.
     */
    'restate_max_tokens' => (int) env('AI_RESTATE_MAX_TOKENS', 200),

    /*
    |--------------------------------------------------------------------------
    | Близкое по теме
    |--------------------------------------------------------------------------
    |
    | Сколько соседнего материала консультант предлагает посмотреть, когда
    | прямого ответа не нашлось или когда нашлось не только это.
    |
    | Даром это не даётся ровно в одном случае — когда отвечать нечем и близкое
    | уходит модели; во всех прочих оно только показывается карточками и в промпт
    | не попадает. Больше пяти советов сразу читатель всё равно не смотрит.
    |
    */

    'related_per_reply' => (int) env('AI_RELATED_PER_REPLY', 4),

    /*
     * Сколько ответственных за курс консультант называет, когда ответа не нашёл.
     *
     * Совет «спросите вот этих» имеет смысл, пока их один-двое: списком из
     * восьми человек сотрудник не воспользуется, а напишет тому, кто ближе
     * сидит. Модели имена не передаются вовсе — их приставляет приложение.
     */
    'experts_per_reply' => (int) env('AI_EXPERTS_PER_REPLY', 3),

    /*
     * Ниже этой близости строка таблицы не относится к вопросу даже отдалённо.
     *
     * Второй, широкий порог рядом с ai.answers_floor. Тот отвечает на вопрос
     * «можно ли этим отвечать», этот — «стоит ли это вообще показывать».
     *
     * На той же шкале, что и все прочие числа здесь (замер на
     * text-embedding-3-small): пересказ того же вопроса — 0.73, соседний вопрос
     * той же темы — 0.52, посторонний — ниже 0.3. Отсюда 0.35: соседнюю тему
     * пропускает, постороннюю нет.
     */
    'answers_related_floor' => (float) env('AI_ANSWERS_RELATED_FLOOR', 0.35),

    /*
    |--------------------------------------------------------------------------
    | Черновик вопросов
    |--------------------------------------------------------------------------
    |
    | Сколько вопросов модель предлагает автору за один разбор урока. Часовая
    | запись разбирает десятки тем, и десяток вопросов покрывает её едва.
    |
    | Цена — деньги и время ожидания: расшифровка читается частями, и на каждую
    | часть уходит запрос. Тридцать вопросов по длинной записи — это несколько
    | секунд и несколько обращений, а не одно.
    |
    */

    'suggested_questions' => (int) env('AI_SUGGESTED_QUESTIONS', 30),

    /*
     * Предел ответа модели при разборе урока — отдельный от того, которым она
     * отвечает сотруднику.
     *
     * Черновик приходит массивом JSON, и обрезанный посередине массив не
     * разбирается вовсе: автор получает не «сколько влезло», а ноль. Тридцати
     * парам «вопрос — ответ» на русском нужно несколько тысяч токенов, тогда
     * как ответу сотруднику хватает тысячи.
     */
    'suggestion_max_tokens' => (int) env('AI_SUGGESTION_MAX_TOKENS', 8192),

    /*
     * Ниже этой близости строка считается не относящейся к вопросу.
     *
     * Порог нужен, чтобы у консультанта был честный «ничего не нашли»: без него
     * на любой вопрос возвращаются восемь наименее посторонних строк, и
     * запасной путь по тексту урока не срабатывает никогда.
     */
    'answers_floor' => (float) env('AI_ANSWERS_FLOOR', 0.45),

    /*
     * Насколько строка может уступать лучшей и всё ещё уходить в промпт.
     *
     * Порог выше отвечает на вопрос «про эту ли тему строка вообще», и одним
     * числом на все вопросы он не отвечает: у одного вопроса лучшее совпадение
     * 0.73, у другого 0.55, и «рядом» у них разное. Эта доля считается от
     * лучшего в конкретной выдаче.
     *
     * Нужна ради слабых моделей. Получив восемь строк, из которых подходит
     * одна, они отвечают не по лучшей, а по приглянувшейся — либо решают, что
     * материала нет вовсе. На замерах 0.75 оставляет одну верную строку там,
     * где абсолютный порог пропускал три.
     */
    'answers_relative_floor' => (float) env('AI_ANSWERS_RELATIVE_FLOOR', 0.75),

    /*
     * Когда ответ отдаётся дословно, без обращения к модели.
     *
     * Порог — насколько близко вопрос сотрудника должен совпасть со строкой;
     * отрыв — насколько лучшая строка должна опережать вторую, иначе выбор
     * между двумя похожими вопросами делается наугад.
     *
     * Оба числа зависят от модели эмбеддингов, а не от предметной области.
     * Слишком высокий порог просто стоит денег; слишком низкий отдаёт ответ не
     * на тот вопрос.
     *
     * 0.75 — не догадка, а замер на text-embedding-3-small: вопрос, слово в
     * слово совпадающий со строкой, даёт 0.77, пересказ той же мысли — 0.73,
     * соседний вопрос той же темы — 0.52. Стоявшие здесь прежде 0.90 не
     * достигались никогда, и короткий путь не срабатывал ни разу.
     *
     * На другой модели эмбеддингов шкала другая: перемерьте, а не переносите.
     */
    'verbatim_threshold' => (float) env('AI_VERBATIM_THRESHOLD', 0.75),

    'verbatim_margin' => (float) env('AI_VERBATIM_MARGIN', 0.05),

    /*
     * За сколькими строками полный проход перестаёт быть дешёвым.
     *
     * Близость считается в приложении: расширения pgvector в базе нет. На
     * тысячах строк это десятки миллисекунд, на десятках тысяч — уже нет. Число
     * ничего не ограничивает, а лишь попадает в журнал предупреждением: это
     * метка, после которой базе нужен настоящий векторный индекс.
     */
    'answers_scan_ceiling' => (int) env('AI_ANSWERS_SCAN_CEILING', 20000),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    |
    | The list of published courses, sent ahead of every question so the model
    | knows what exists and can say "we have nothing on that" rather than
    | guessing from a search that returned nothing.
    |
    | It is identical for every reader, which is what lets one cache entry
    | serve the whole company. Prompt caching only engages past a per-model
    | minimum prefix (4096 tokens on Haiku), so on a small base it simply does
    | not kick in — and costs nothing extra for trying.
    |
    */

    'catalogue_cache_minutes' => (int) env('AI_CATALOGUE_CACHE_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Two ways to present the key, because the two kinds of endpoint expect
    | different things. Anthropic itself authenticates by an `X-Api-Key`
    | header; most proxies and tunnels in front of it expect the key as
    | `Authorization: Bearer`. Fill in whichever one the endpoint asks for —
    | setting both sends both headers, which is merely untidy against
    | Anthropic and a leaked key against a proxy that logs what it receives.
    |
    | Left to the SDK's own resolution when unset: it reads ANTHROPIC_API_KEY
    | and ANTHROPIC_AUTH_TOKEN from the environment.
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'auth_token' => env('ANTHROPIC_AUTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Where the API is reached. Empty means api.anthropic.com; set it to send
    | requests through a tunnel or a proxy instead — the deployment may not be
    | able to open a connection to Anthropic directly.
    |
    | A path is kept: a proxy mounted at https://tunnel.example.com/anthropic
    | receives /anthropic/v1/messages. Whatever is behind it has to speak the
    | Messages API — this swaps the address, not the protocol.
    |
    */

    'base_url' => env('ANTHROPIC_BASE_URL'),

];
