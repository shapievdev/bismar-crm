<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\AnalyticsAnswerOutcome;
use App\Enums\Permission;
use App\Models\AnalyticsQuestion;
use App\Models\User;
use App\Support\Analytics\ClickHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * ИИ-аналитик: вопрос словами, ответ словами и собранным дашбордом.
 *
 * Цифры здесь не проверяются — они принадлежат витрине, и подделывать их значило
 * бы проверять собственную подделку. Проверяется то, что принадлежит платформе,
 * и прежде всего **граница**: ни одно слово из ответа модели не доходит до
 * запроса, не пройдя через словарь. Выдуманный показатель, выдуманный разрез,
 * «сто строк в рейтинге» — всё это должно отсекаться здесь, а не в ClickHouse.
 */
final class AiAnalystTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Кому можно ---------- */

    public function test_asking_needs_the_right_to_see_the_numbers(): void
    {
        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('analytics.ai.history'))
            ->assertForbidden();
    }

    public function test_a_guest_is_not_let_in(): void
    {
        $this->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertUnauthorized();
    }

    /* ---------- Обычный ответ ---------- */

    public function test_the_analyst_answers_in_words_and_composes_a_dashboard(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Выручку свёл в плитку, магазины — в рейтинг.',
            'dashboard' => [
                'title' => 'Продажи за месяц',
                'slice' => ['preset' => 'month', 'granularity' => 'week'],
                'widgets' => [
                    ['type' => 'kpi', 'metric' => 'revenue', 'span' => 3],
                    ['type' => 'line', 'title' => 'Выручка', 'metrics' => ['revenue', 'profit'], 'span' => 9],
                    ['type' => 'bars', 'title' => 'Магазины', 'dimension' => 'warehouse', 'metric' => 'revenue', 'span' => 12],
                ],
            ],
        ])));

        $response = $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'как идут продажи по магазинам?'])
            ->assertOk();

        $response->assertJsonPath('data.answer', 'Выручку свёл в плитку, магазины — в рейтинг.')
            ->assertJsonPath('data.dashboard.title', 'Продажи за месяц')
            // Панели приходят уже посчитанными: описание без цифр означало бы
            // сетку, которая наполнится неизвестно когда.
            ->assertJsonPath('data.dashboard.panels.0.type', 'kpi')
            ->assertJsonPath('data.dashboard.panels.1.type', 'line')
            ->assertJsonPath('data.dashboard.panels.2.type', 'bars');

        $stored = AnalyticsQuestion::query()->sole();

        $this->assertSame(AnalyticsAnswerOutcome::Composed, $stored->outcome);
        // Хранится описание, а не цифры: открытый завтра разговор должен
        // показать, как дела завтра.
        $this->assertIsArray($stored->dashboard);
        $this->assertArrayNotHasKey('panels', $stored->dashboard);
    }

    /**
     * Вопрос, на который отвечают словами.
     *
     * Половина вопросов к аналитике панелью не отвечается, и пустая сетка вместо
     * ответа была бы хуже его отсутствия.
     */
    public function test_a_question_about_nothing_countable_is_answered_in_words_only(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Я умею показывать выручку, прибыль, маржу и возвраты в разрезах складов, менеджеров и товаров.',
            'dashboard' => null,
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'что ты умеешь?'])
            ->assertOk()
            ->assertJsonPath('data.dashboard', null);

        $this->assertSame(AnalyticsAnswerOutcome::Explained, AnalyticsQuestion::query()->sole()->outcome);
    }

    /* ---------- Граница: что модель выдумала ---------- */

    /**
     * Выдуманный показатель до запроса не доходит.
     *
     * Самая важная проверка раздела. Модель на бою слабая, и «покажи
     * рентабельность по EBITDA» она предложит рано или поздно. Такая панель
     * должна исчезнуть, а не превратиться в запрос с неизвестным именем столбца.
     */
    public function test_a_made_up_metric_never_reaches_the_query(): void
    {
        $clickhouse = $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Собрал.',
            'dashboard' => [
                'title' => 'Выдумка',
                'widgets' => [
                    ['type' => 'kpi', 'metric' => 'ebitda', 'span' => 3],
                    ['type' => 'bars', 'dimension' => 'planeta', 'metric' => 'revenue', 'span' => 6],
                    ['type' => 'pie', 'dimension' => 'warehouse', 'metric' => 'revenue', 'span' => 6],
                    ['type' => 'kpi', 'metric' => 'revenue', 'span' => 3],
                ],
            ],
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'покажи EBITDA по планетам'])
            ->assertOk()
            // Уцелела одна панель из четырёх — та единственная, что названа
            // словарём. Остальные выброшены по одной: отказ от дашборда целиком
            // означал бы, что сотрудник не видит ничего из-за одной ошибки.
            ->assertJsonCount(1, 'data.dashboard.panels')
            ->assertJsonPath('data.dashboard.panels.0.type', 'kpi');

        foreach ($clickhouse->queries as $query) {
            $this->assertStringNotContainsString('ebitda', mb_strtolower($query['sql']));
            $this->assertStringNotContainsString('planeta', mb_strtolower($query['sql']));
        }
    }

    /** Ни одной уцелевшей панели — значит дашборда нет, но слова остаются. */
    public function test_when_nothing_survives_the_words_remain(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Вот что получилось.',
            'dashboard' => ['title' => 'Пусто', 'widgets' => [['type' => 'pie', 'metric' => 'ebitda']]],
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'что-нибудь'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Вот что получилось.')
            ->assertJsonPath('data.dashboard', null);
    }

    /**
     * Показатель, который в этом месте не считается, панелью не становится.
     *
     * Любимая просьба модели — «возвраты по брендам»: по словарю есть и
     * возвраты, и бренды, а отчёты такой строки не считают. Панель выходила
     * пустой, и ответ словами ещё приглашал на неё посмотреть — хуже этого
     * только обещание, которого никто не заметил.
     */
    public function test_a_metric_that_is_not_counted_in_a_ranking_is_dropped(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Возвраты свёл в плитку.',
            'dashboard' => [
                'widgets' => [
                    // Так — можно: итоги возвраты знают.
                    ['type' => 'kpi', 'metric' => 'returns', 'span' => 3],
                    // А так — нет: раскладка по брендам возвратов не считает.
                    ['type' => 'bars', 'title' => 'Бренды по возвратам', 'dimension' => 'brand', 'metric' => 'returns'],
                ],
            ],
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'возвраты по брендам'])
            ->assertOk()
            ->assertJsonCount(1, 'data.dashboard.panels')
            ->assertJsonPath('data.dashboard.panels.0.type', 'kpi');
    }

    /** Предел строк в рейтинге свой, а не тот, который назвала модель. */
    public function test_the_limit_is_ours_not_the_one_the_model_asked_for(): void
    {
        $clickhouse = $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Собрал.',
            'dashboard' => [
                'widgets' => [
                    ['type' => 'bars', 'dimension' => 'warehouse', 'metric' => 'revenue', 'limit' => 9999],
                ],
            ],
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'все магазины'])
            ->assertOk();

        foreach ($clickhouse->queries as $query) {
            if (array_key_exists('limit', $query['bindings'])) {
                $this->assertLessThanOrEqual(25, (int) $query['bindings']['limit']);
            }
        }
    }

    /* ---------- Когда что-то сломалось ---------- */

    /**
     * Модель не ответила — экран не падает.
     *
     * Сбой не попадает ни в переписку, ни в память модели: иначе она училась бы
     * отвечать сообщениями об ошибках.
     */
    public function test_an_unreachable_model_is_explained_and_not_remembered(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::unreachable());

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertOk()
            ->assertJsonPath('data.dashboard', null)
            ->assertJsonPath('data.question_id', null);

        $this->assertSame(AnalyticsAnswerOutcome::Failed, AnalyticsQuestion::query()->sole()->outcome);

        $this->actingAs($this->analyst())
            ->getJson(route('analytics.ai.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Бессмыслица вместо JSON — тот же честный отказ. */
    public function test_nonsense_instead_of_json_is_refused(): void
    {
        $this->fakeClickHouse();
        $this->fakeModel(FakeAnthropicTransport::replying('Конечно! Сейчас посмотрю ваши данные...'));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertOk()
            ->assertJsonPath('data.dashboard', null);

        $this->assertSame(AnalyticsAnswerOutcome::Failed, AnalyticsQuestion::query()->sole()->outcome);
    }

    /**
     * Недоступная аналитика не отменяет слов.
     *
     * ClickHouse стоит на сервере компании и доезжает туннелем: «не ответил» —
     * обыкновенное положение дел, а не поломка платформы.
     */
    public function test_an_unavailable_warehouse_does_not_swallow_the_answer(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::down());
        $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Выручка в плитке.',
            'dashboard' => ['widgets' => [['type' => 'kpi', 'metric' => 'revenue']]],
        ])));

        $response = $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertOk()
            ->assertJsonPath('data.dashboard', null);

        // Слова остались, и к ним дописано, почему панели нет.
        $this->assertStringContainsString('Выручка в плитке.', (string) $response->json('data.answer'));
        $this->assertStringContainsString('сервер аналитики не ответил', (string) $response->json('data.answer'));
    }

    /* ---------- Разговор ---------- */

    /**
     * Прошлый круг уходит модели вместе с описанием дашборда.
     *
     * То, ради чего раздел — разговор, а не форма: «а теперь по неделям» меняет
     * в прошлом описании одно поле, а не собирает его заново. Без описания в
     * памяти модель не помнит, что собирала.
     */
    public function test_the_previous_dashboard_goes_back_to_the_model(): void
    {
        $this->fakeClickHouse();
        $analyst = $this->analyst();

        AnalyticsQuestion::factory()->composed()->create([
            'user_id' => $analyst->getKey(),
            'question' => 'продажи по магазинам',
        ]);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Переложил на недели.',
            'dashboard' => ['widgets' => [['type' => 'kpi', 'metric' => 'revenue']]],
        ])));

        $this->actingAs($analyst)
            ->postJson(route('analytics.ai.ask'), ['question' => 'а теперь по неделям'])
            ->assertOk();

        $sent = (string) json_encode($transport->payload(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('продажи по магазинам', $sent);
        $this->assertStringContainsString('Продажи за месяц', $sent);
        $this->assertStringContainsString('а теперь по неделям', $sent);
    }

    /** Чужая переписка в память не попадает. */
    public function test_one_persons_questions_do_not_build_on_anothers(): void
    {
        $this->fakeClickHouse();

        AnalyticsQuestion::factory()->create([
            'user_id' => User::factory()->create()->getKey(),
            'question' => 'чужой вопрос про чужой отдел',
        ]);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Готово.',
            'dashboard' => null,
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'мой вопрос'])
            ->assertOk();

        $this->assertStringNotContainsString(
            'чужой вопрос',
            (string) json_encode($transport->payload(), JSON_UNESCAPED_UNICODE),
        );
    }

    /** Словарь уходит модели: без него она не знает ни показателей, ни оговорок. */
    public function test_the_vocabulary_and_the_caveats_go_to_the_model(): void
    {
        $this->fakeClickHouse();
        $transport = $this->fakeModel(FakeAnthropicTransport::replying((string) json_encode([
            'answer' => 'Готово.', 'dashboard' => null,
        ])));

        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => 'как продажи?'])
            ->assertOk();

        $system = (string) json_encode($transport->payload()['system'] ?? '', JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('without_cost_share', $system);
        $this->assertStringContainsString('warehouse', $system);
        // Оговорка о среднем чеке в розничных каналах — та, без которой модель
        // отвечает уверенно и неверно.
        $this->assertStringContainsString('average_order', $system);
    }

    /* ---------- Своя переписка ---------- */

    public function test_history_shows_only_your_own_and_recounts_the_panels(): void
    {
        $this->fakeClickHouse();
        $analyst = $this->analyst();

        AnalyticsQuestion::factory()->composed()->create([
            'user_id' => $analyst->getKey(),
            'question' => 'мой вопрос',
        ]);
        AnalyticsQuestion::factory()->create(['user_id' => User::factory()->create()->getKey()]);

        $this->actingAs($analyst)
            ->getJson(route('analytics.ai.history'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'мой вопрос')
            ->assertJsonPath('data.0.composed', true)
            // Панели пересчитаны при чтении: вчерашний разговор показывает, как
            // дела сегодня.
            ->assertJsonPath('data.0.dashboard.panels.0.type', 'kpi');
    }

    public function test_a_forgotten_conversation_is_gone_from_the_screen(): void
    {
        $this->fakeClickHouse();
        $analyst = $this->analyst();

        AnalyticsQuestion::factory()->create(['user_id' => $analyst->getKey()]);

        $this->actingAs($analyst)->deleteJson(route('analytics.ai.forget'))->assertNoContent();

        $this->actingAs($analyst)
            ->getJson(route('analytics.ai.history'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Запись остаётся: журнал ведётся ради вопросов, а не ради людей.
        $this->assertSame(1, AnalyticsQuestion::query()->count());
    }

    public function test_an_empty_question_is_refused(): void
    {
        $this->actingAs($this->analyst())
            ->postJson(route('analytics.ai.ask'), ['question' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
    }

    /* ---------- Обстановка ---------- */

    private function analyst(): User
    {
        return $this->userWith(Permission::ViewAnalytics);
    }

    private function fakeClickHouse(): FakeClickHouse
    {
        $clickhouse = FakeClickHouse::empty();

        $this->app->instance(ClickHouse::class, $clickhouse);

        return $clickhouse;
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        // Ключ — чтобы действие не остановилось на «доступ к модели не
        // настроен»: сам запрос всё равно уходит в поддельный транспорт.
        config()->set('ai.api_key', 'test-key');

        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }
}
