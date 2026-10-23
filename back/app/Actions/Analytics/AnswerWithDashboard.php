<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use Anthropic\Client;
use App\Data\Analytics\DashboardSpec;
use App\Enums\AnalyticsAnswerOutcome;
use App\Models\AnalyticsQuestion;
use App\Models\User;
use App\Support\Ai\ModelSettings;
use App\Support\Analytics\AnalyticsUnavailable;
use App\Support\Analytics\DashboardExecutor;
use App\Support\Analytics\DashboardVocabulary;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вопрос о цифрах — ответ словами и собранным дашбордом.
 *
 * Один вызов модели на вопрос. Модель не считает и не запрашивает ничего: она
 * выбирает из словаря (DashboardVocabulary), какие панели собрать и за какой
 * период, а считает их потом наш же исполнитель теми же отчётами, что рисуют
 * вкладки аналитики. Отсюда главное свойство раздела: цифра у ИИ-аналитика та
 * же, что на вкладке продаж, — не «посчитанная так же», а буквально та же.
 *
 * Ответ приходит двумя частями, и обе нужны. Слова — потому что половина
 * вопросов к аналитике вообще не о картинке: «почему маржа упала» требует
 * объяснения, опирающегося на цифры. Панель — потому что без неё слова не
 * проверить.
 *
 * Разговор помнится: предыдущие круги уходят модели настоящими репликами, и в
 * них лежит прошлый дашборд описанием. Это и есть то, ради чего раздел —
 * разговор, а не форма: «а теперь по неделям» меняет в прошлом описании одно
 * поле, а не собирает его заново.
 *
 * Модель на бою слабая (gpt-4o-mini через чужой шлюз), и всё здесь рассчитано
 * на это: словарь перечислен подробно, пример ответа приложен, негодные панели
 * выбрасываются по одной, а не отменяют ответ, и сбой модели не роняет экран.
 */
final readonly class AnswerWithDashboard
{
    /**
     * Сколько прошлых кругов разговора уходит модели.
     *
     * Два, а не десять: в каждом круге лежит описание дашборда, и десять
     * съели бы весь предел запроса — а уточняют обычно только что сказанное.
     */
    private const REMEMBERED_TURNS = 2;

    public function __construct(
        private Client $client,
        private ModelSettings $settings,
        private DashboardVocabulary $vocabulary,
        private DashboardExecutor $executor,
    ) {}

    /**
     * @return array{answer: string, dashboard: array<string, mixed>|null, question_id: int|null}
     */
    public function handle(User $reader, string $question): array
    {
        if (! $this->settings->isConfigured()) {
            return $this->failure($reader, $question, 'Доступ к модели не настроен — скажите администратору.');
        }

        $started = (int) (microtime(true) * 1000);

        try {
            $reply = $this->ask($reader, $question);
        } catch (Throwable $failure) {
            report($failure);

            return $this->failure(
                $reader,
                $question,
                'Не удалось спросить модель. Попробуйте ещё раз: обычно это обрыв связи с ней, а не поломка аналитики.',
            );
        }

        $answer = trim((string) ($reply['answer'] ?? ''));
        $spec = is_array($reply['dashboard'] ?? null)
            ? DashboardSpec::fromModel($reply['dashboard'], $this->vocabulary)
            : null;

        if ($answer === '' && $spec === null) {
            return $this->failure($reader, $question, 'Модель ответила непонятным. Переспросите другими словами.');
        }

        /*
         * Цифры считаются здесь же, а не при показе: модель могла выбрать срез,
         * на котором ClickHouse недоступен или пуст, и узнать об этом надо
         * сейчас — чтобы сохранить разговор честным. Недоступная аналитика при
         * этом не отменяет слов: сервер стоит у компании и доезжает туннелем,
         * «не ответил» — обыкновенное дело.
         */
        $dashboard = null;

        if ($spec !== null) {
            try {
                $dashboard = $this->executor->render($spec);
            } catch (AnalyticsUnavailable $unavailable) {
                $answer = trim($answer."\n\nПанель собрать не удалось: сервер аналитики не ответил. Повторите вопрос чуть позже.");
            } catch (Throwable $failure) {
                report($failure);

                $answer = trim($answer."\n\nПанель собрать не удалось — цифры не посчитались. Попробуйте спросить иначе.");
            }
        }

        $stored = AnalyticsQuestion::query()->create([
            'user_id' => $reader->getKey(),
            'question' => $question,
            'answer' => $answer,
            // Описанием, а не цифрами: открытый завтра разговор должен показать,
            // как дела завтра.
            'dashboard' => $dashboard === null ? null : $spec?->toArray(),
            'outcome' => $dashboard === null
                ? AnalyticsAnswerOutcome::Explained
                : AnalyticsAnswerOutcome::Composed,
            'model' => $this->settings->model(),
            'duration_ms' => (int) (microtime(true) * 1000) - $started,
        ]);

        return [
            'answer' => $answer,
            'dashboard' => $dashboard,
            'question_id' => (int) $stored->getKey(),
        ];
    }

    /**
     * Один вызов модели.
     *
     * @return array<string, mixed>
     */
    private function ask(User $reader, string $question): array
    {
        $message = $this->client->messages->create(
            model: $this->settings->model(),
            // Свой предел, не тот, которым консультант отвечает сотруднику: там
            // хватает тысячи токенов на абзац текста, а здесь в ответе ещё и
            // описание восьми панелей. Обрезанный посередине JSON не
            // разбирается вовсе — сотрудник получил бы не «сколько влезло», а
            // ничего.
            maxTokens: (int) config('analytics.ai.max_tokens'),
            system: $this->system(),
            messages: [
                ...$this->conversation($reader),
                ['role' => 'user', 'content' => $question],
            ],
        );

        $raw = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $raw .= $block->text;
            }
        }

        return $this->decode($raw);
    }

    /**
     * Прошлые круги разговора — настоящими репликами.
     *
     * Своими словами модели возвращается то, что она сказала: и объяснение, и
     * описание дашборда. Без описания «а теперь по неделям» значило бы для неё
     * ровно ничего — она не помнит, что собирала.
     *
     * @return list<array{role: string, content: string}>
     */
    private function conversation(User $reader): array
    {
        $turns = AnalyticsQuestion::query()
            ->conversationOf($reader)
            // По ключу вслед за временем: два вопроса в одну секунду время не
            // различает, и порядок остался бы на усмотрение базы.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::REMEMBERED_TURNS)
            ->get(['question', 'answer', 'dashboard']);

        $messages = [];

        // Свежие выбираем, старые показываем: модель читает разговор сверху
        // вниз, как и человек.
        foreach ($turns->reverse() as $turn) {
            $said = ['answer' => (string) $turn->answer];

            if (is_array($turn->dashboard)) {
                $said['dashboard'] = $turn->dashboard;
            }

            $messages[] = ['role' => 'user', 'content' => (string) $turn->question];
            $messages[] = [
                'role' => 'assistant',
                'content' => (string) json_encode($said, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $messages;
    }

    /**
     * Что модель умеет и чего от неё ждут.
     *
     * Подробнее, чем хотелось бы, и это осознанно: подсказка написана под
     * слабую модель, которой нужен и перечень, и пример, и прямой запрет на
     * выдумывание имён.
     */
    private function system(): string
    {
        return <<<TEXT
        Ты — аналитик торговой сети BISMAR. Сотрудник спрашивает о продажах, ты отвечаешь
        словами и собираешь дашборд из готовых панелей.

        Считать ты ничего не можешь и не должен: цифры посчитает приложение по твоему
        описанию. Твоё дело — выбрать, какие панели показать, за какой период и в каком
        разрезе.

        # Как отвечать

        Отвечай **только** объектом JSON, без пояснений вокруг него:

        {"answer": "текст для сотрудника", "dashboard": {"title": "…", "slice": {…}, "widgets": [{…}]}}

        Поле `dashboard` ставь `null`, когда вопрос не про панель: объяснение, уточнение,
        «что ты умеешь». Пустая сетка вместо ответа хуже, чем ответ словами.

        Пример полного ответа на «как идут продажи за квартал по магазинам»:

        {"answer": "Выручка за квартал — я свёл её в плитки, а по магазинам дал рейтинг. Обрати внимание на долю выручки без себестоимости: там, где она велика, маржа приблизительная.",
         "dashboard": {"title": "Продажи за квартал по магазинам",
          "slice": {"preset": "quarter", "granularity": "week"},
          "widgets": [
            {"type": "kpi", "metric": "revenue", "span": 3},
            {"type": "kpi", "metric": "profit", "span": 3},
            {"type": "kpi", "metric": "margin", "span": 3},
            {"type": "kpi", "metric": "orders", "span": 3},
            {"type": "line", "title": "Выручка и прибыль", "metrics": ["revenue", "profit"], "span": 8, "rows": 2},
            {"type": "donut", "title": "Каналы продаж", "dimension": "channel", "metric": "revenue", "span": 4},
            {"type": "bars", "title": "Магазины по выручке", "dimension": "warehouse", "metric": "revenue", "limit": 12, "span": 12}
          ]}}

        # Правила, которые нельзя нарушать

        1. Имена показателей, разрезов и видов панелей бери **только** из перечней ниже.
           Выдуманное имя приложение выбросит, и панель не появится.
        2. Сетка — двенадцать колонок. `span` плитки — 3, графика и рейтинга — от 6 до 12.
           Сумма в ряду не должна превышать 12.
        3. Панелей не больше восьми. Отвечай на заданный вопрос, а не на все соседние.
        4. В `answer` говори о том, что видно на панелях, и называй период. Не выдумывай
           чисел: ты их не знаешь — их посчитает приложение. Говори о том, **что смотреть**.
        5. Если вопрос упирается в оговорки ниже — скажи об этом прямо в `answer`.
        6. Отвечай по-русски.

        {$this->vocabulary->forPrompt()}
        TEXT;
    }

    /**
     * Вытащить объект JSON из того, что прислала модель.
     *
     * Слабая модель охотно обкладывает ответ пояснениями и ограждением
     * markdown, хотя её просили не делать ни того, ни другого. Берём первый
     * объект от фигурной скобки до последней — разбирать нечего, если его нет.
     *
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/\{.*}/s', $raw, $match) !== 1) {
            Log::warning('ИИ-аналитик: в ответе модели нет объекта JSON.', ['raw' => mb_substr($raw, 0, 500)]);

            return [];
        }

        $decoded = json_decode($match[0], true);

        if (! is_array($decoded)) {
            Log::warning('ИИ-аналитик: объект JSON не разобрался.', ['raw' => mb_substr($match[0], 0, 500)]);

            return [];
        }

        return $decoded;
    }

    /**
     * Сбой — записью в журнал и честной строкой сотруднику.
     *
     * В переписку такая запись не попадает и модели не возвращается (см.
     * AnalyticsAnswerOutcome::Failed): иначе она училась бы отвечать
     * сообщениями об ошибках.
     *
     * @return array{answer: string, dashboard: null, question_id: null}
     */
    private function failure(User $reader, string $question, string $because): array
    {
        AnalyticsQuestion::query()->create([
            'user_id' => $reader->getKey(),
            'question' => $question,
            'answer' => $because,
            'outcome' => AnalyticsAnswerOutcome::Failed,
            'model' => $this->settings->model(),
        ]);

        return ['answer' => $because, 'dashboard' => null, 'question_id' => null];
    }
}
