<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\DepartmentRole;
use App\Enums\DismissalReason;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\StaffReportExport;
use App\Models\StaffReportLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Аналитика штата на выдуманном кадровом годе.
 *
 * Проверяется не «ручка отвечает двумястами», а арифметика, ради которой отчёт
 * и заводили: численность считается по датам, а не по сегодняшнему положению;
 * края периода входят в него; текучесть делится на среднесписочную, а не на
 * остаток; ранняя текучесть меряет потерянный найм. Ошибись здесь на единицу —
 * и кадровая служба примет решение по неверной цифре, ничего не заподозрив.
 *
 * Вторая половина файла — про 152-ФЗ: директор не видит соседнее направление
 * даже через адрес, выгрузка с фамилиями оставляет след, а наружу не уходит ни
 * одной фамилии.
 */
final class StaffAnalyticsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Год, на котором ставятся все опыты: 2026-й, целиком в прошлом. */
    private const FROM = '2026-01-01';

    private const TO = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        // «Сегодня» закреплено: стаж и онбординг считаются от текущего дня, и
        // отчёт, верный в понедельник, к субботе разъехался бы сам собой.
        $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    }

    /* ---------- Арифметика ---------- */

    /**
     * Численность — это состояние на дату, а не положение «на сегодня».
     *
     * Главное свойство отчёта: тот, кто уволился в декабре, в августовской
     * численности обязан стоять. Считай отчёт по нынешнему признаку «уволен» —
     * и прошлое переписывалось бы каждым увольнением.
     */
    public function test_headcount_is_taken_on_the_date_not_today(): void
    {
        $this->hire('2025-06-01');                      // работал весь год
        $this->hire('2026-03-10');                      // пришёл в марте
        $this->hire('2025-01-01', dismissed: '2026-07-20'); // ушёл в июле

        $response = $this->report(from: '2026-01-01', to: '2026-06-30');

        $response
            ->assertJsonPath('data.summary.headcount_start', 2)
            ->assertJsonPath('data.summary.headcount_end', 3)
            ->assertJsonPath('data.summary.hired', 1)
            // Июльское увольнение в полугодие не попадает — и не должно.
            ->assertJsonPath('data.summary.left', 0);
    }

    /** Края периода входят в него: принятый первого января принят за год. */
    public function test_the_period_includes_both_of_its_edges(): void
    {
        $this->hire(self::FROM);
        $this->hire('2025-12-31', dismissed: self::TO);

        $this->report()
            ->assertJsonPath('data.summary.hired', 1)
            ->assertJsonPath('data.summary.left', 1);
    }

    /**
     * Текучесть делится на среднесписочную, а не на остаток.
     *
     * Десять человек, взятых первого числа и уволенных тридцатого, полусуммой
     * краёв выглядят спокойным месяцем. Среднее по дням показывает правду.
     */
    public function test_turnover_divides_by_the_average_headcount(): void
    {
        // Двое работают весь месяц, двое — пришли и ушли внутри него.
        $this->hire('2025-01-01');
        $this->hire('2025-01-01');
        $this->hire('2026-04-02', dismissed: '2026-04-28');
        $this->hire('2026-04-02', dismissed: '2026-04-28');

        $response = $this->report(from: '2026-04-01', to: '2026-04-30');

        $average = (float) $response->json('data.summary.average_headcount');

        // Ни двое (остаток), ни четверо (списочный максимум): между ними.
        $this->assertGreaterThan(2.0, $average);
        $this->assertLessThan(4.0, $average);

        // С допуском: доля приходит числом, и сравнивать дробное на точное
        // совпадение значит проверять формат JSON, а не арифметику.
        $this->assertEqualsWithDelta(
            round(2 / $average * 100, 1),
            (float) $response->json('data.summary.turnover'),
            0.05,
        );
    }

    /**
     * Ранняя текучесть — доля потерянного найма, а не доля выживших.
     *
     * Числитель и знаменатель здесь про разных людей намеренно: вопрос стоит
     * «сколько найма за период пропало впустую».
     */
    public function test_early_turnover_measures_hiring_wasted(): void
    {
        $this->hire('2026-02-01');                             // прижился
        $this->hire('2026-02-01', dismissed: '2026-03-15');    // 42 дня
        $this->hire('2026-02-01', dismissed: '2026-03-15');    // 42 дня
        $this->hire('2026-02-01', dismissed: '2026-11-01');    // 273 дня — не ранняя
        $this->hire('2025-01-01', dismissed: '2026-02-05');    // ушёл, но принят не в периоде

        $response = $this->report()
            ->assertJsonPath('data.summary.hired', 4)
            ->assertJsonPath('data.summary.left', 4)
            ->assertJsonPath('data.summary.early_left', 2);

        $this->assertEqualsWithDelta(50.0, (float) $response->json('data.summary.early_turnover'), 0.05);
    }

    /**
     * Карточка без даты приёма не попадает ни в одну цифру — и считается
     * отдельно.
     *
     * Это мера доверия к отчёту: промолчи о ней — и «принято трое» показывалось
     * бы там, где принято тридцать.
     */
    public function test_a_card_without_a_hire_date_is_counted_apart(): void
    {
        $this->hire('2026-05-01');
        $this->place(User::factory()->create(['hired_at' => null]), $this->crew());

        $this->report()
            ->assertJsonPath('data.summary.headcount_end', 1)
            ->assertJsonPath('data.summary.without_hire_date', 1);

        $people = $this->actingAs($this->hr())
            ->getJson(route('analytics.staff.people', $this->asked(extra: ['slice' => 'without-hire-date'])))
            ->assertOk();

        $this->assertCount(1, $people->json('data.people'));
    }

    /** Месяц без единого движения остаётся на графике провалом, а не исчезает. */
    public function test_movement_keeps_an_empty_month_in_place(): void
    {
        $this->hire('2026-01-15');
        $this->hire('2026-03-15');

        $months = $this->report(from: '2026-01-01', to: '2026-03-31')->json('data.movement');

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($months, 'month'));
        $this->assertSame([1, 0, 1], array_column($months, 'hired'));
    }

    /**
     * Непроставленная причина ухода не растворяется в процентах.
     *
     * Сложи её в «другое» — и выйдет, что каждый пятый ушёл по загадочной
     * причине, хотя её просто не записали.
     */
    public function test_an_unrecorded_reason_stays_out_of_the_shares(): void
    {
        $this->hire('2025-01-01', dismissed: '2026-06-01', reason: DismissalReason::Own);
        $this->hire('2025-01-01', dismissed: '2026-06-02', reason: DismissalReason::Own);
        $this->hire('2025-01-01', dismissed: '2026-06-03');

        $reasons = $this->report()->json('data.reasons');

        $this->assertSame(2, $reasons['total']);
        $this->assertSame(1, $reasons['unknown']);
        $this->assertEqualsWithDelta(100.0, (float) $reasons['rows'][0]['share'], 0.05);
    }

    /* ---------- Кто что видит ---------- */

    /** Линейному сотруднику отчёт закрыт вовсе. */
    public function test_the_shop_floor_cannot_open_the_report(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('analytics.staff'))
            ->assertForbidden();
    }

    /**
     * Директор направления не посмотрит соседнее, даже подставив его номер.
     *
     * Фильтр приходит от экрана, а экран — от человека: без сужения правами
     * область видимости задавал бы адрес в браузере.
     */
    public function test_a_director_cannot_reach_a_neighbouring_branch(): void
    {
        [$retail, $wholesale] = [$this->department('Розница'), $this->department('Опт')];

        $this->hire('2026-02-01', department: $retail);
        $this->hire('2026-02-01', department: $wholesale);
        $this->hire('2026-03-01', department: $wholesale);

        $director = $this->director($retail);

        // Своё направление — своё.
        $this->actingAs($director)
            ->getJson(route('analytics.staff', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertJsonPath('data.summary.hired', 1);

        // Чужое, спрошенное прямо, — пусто, а не отказ: соседнего направления
        // для него просто не существует.
        $this->actingAs($director)
            ->getJson(route('analytics.staff', [
                'from' => self::FROM, 'to' => self::TO, 'departments' => [$wholesale->id],
            ]))
            ->assertOk()
            ->assertJsonPath('data.summary.hired', 0);
    }

    /** Ветка целиком: направление — это поддерево, а не строка справочника. */
    public function test_a_director_sees_the_whole_branch_below(): void
    {
        $retail = $this->department('Розница');
        $shop = $this->department('Магазин на Ленина', parent: $retail);

        $this->hire('2026-02-01', department: $shop);

        $this->actingAs($this->director($retail))
            ->getJson(route('analytics.staff', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertJsonPath('data.summary.hired', 1);
    }

    /* ---------- Выгрузка и след ---------- */

    /** Выгрузка с фамилиями оставляет запись в журнале — требование 152-ФЗ. */
    public function test_an_export_with_names_is_written_to_the_journal(): void
    {
        $this->hire('2026-02-01');
        $this->hire('2026-03-01');

        $hr = $this->hr();

        $this->actingAs($hr)
            ->get(route('analytics.staff.export', $this->asked(extra: ['format' => 'csv', 'names' => 1])))
            ->assertOk()
            ->assertDownload('shtat-2026-01-01-2026-12-31-spisok.csv');

        $entry = StaffReportExport::query()->sole();

        $this->assertTrue($entry->with_names);
        $this->assertSame($hr->id, $entry->user_id);
        $this->assertSame(2, $entry->rows);
        $this->assertSame(self::FROM, $entry->filters['from']);
    }

    /** Выгрузка сводки тоже пишется, но фамилий за собой не числит. */
    public function test_an_export_without_names_says_so(): void
    {
        $this->hire('2026-02-01');

        $this->actingAs($this->hr())
            ->get(route('analytics.staff.export', $this->asked(extra: ['format' => 'xlsx', 'names' => 0])))
            ->assertOk();

        $this->assertFalse(StaffReportExport::query()->sole()->with_names);
    }

    /** В выгрузке ровно то, что человеку видно на экране, — не больше. */
    public function test_an_export_carries_only_what_the_viewer_may_see(): void
    {
        [$retail, $wholesale] = [$this->department('Розница'), $this->department('Опт')];

        $this->hire('2026-02-01', department: $retail);
        $this->hire('2026-02-01', department: $wholesale);
        $this->hire('2026-02-02', department: $wholesale);

        // Срез «принятые за период»: сам директор в него не попадёт — даты
        // приёма у заведённого тестом человека нет, и речь тут не о нём.
        $this->actingAs($this->director($retail))
            ->get(route('analytics.staff.export', [
                'from' => self::FROM, 'to' => self::TO,
                'format' => 'csv', 'names' => 1, 'slice' => 'hired',
            ]))
            ->assertOk();

        $this->assertSame(1, StaffReportExport::query()->sole()->rows);
    }

    /** Журнал открыт тому, кто отвечает за компанию, а не за своё направление. */
    public function test_the_journal_is_closed_to_a_director(): void
    {
        $this->actingAs($this->director($this->department('Розница')))
            ->getJson(route('analytics.staff.exports'))
            ->assertForbidden();

        $this->actingAs($this->hr())
            ->getJson(route('analytics.staff.exports'))
            ->assertOk();
    }

    /* ---------- Ссылка наружу ---------- */

    /** По ссылке приходят цифры — и ни одной фамилии. */
    public function test_the_external_link_carries_no_names(): void
    {
        $person = $this->hire('2026-02-01');
        $person->forceFill(['last_name' => 'Куприянова', 'first_name' => 'Ева'])->save();

        $token = $this->actingAs($this->hr())
            ->postJson(route('analytics.staff.links.store'), $this->asked(extra: ['days' => 7]))
            ->assertCreated()
            ->json('data.token');

        $response = $this->getJson(route('shared.staff-report', $token))->assertOk();

        $response->assertJsonPath('data.summary.hired', 1);

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('Куприянова', $body);
        // И не только фамилия: списка людей у ссылки нет как понятия.
        $this->assertArrayNotHasKey('people', $response->json('data'));
        $this->assertArrayNotHasKey('without_hire_date', $response->json('data.summary'));
    }

    /** Отозванная и просроченная ссылка молчат — каждая по-своему. */
    public function test_a_dead_link_refuses_to_open(): void
    {
        $expired = StaffReportLink::query()->create([
            'filters' => ['from' => self::FROM, 'to' => self::TO],
            'expires_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->getJson(route('shared.staff-report', $expired->token))
            ->assertStatus(410)
            ->assertJsonPath('message', 'Срок действия ссылки истёк.');

        $revoked = StaffReportLink::query()->create([
            'filters' => ['from' => self::FROM, 'to' => self::TO],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);
        $revoked->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        $this->getJson(route('shared.staff-report', $revoked->token))
            ->assertStatus(410)
            ->assertJsonPath('message', 'Ссылка отозвана.');
    }

    /**
     * Ссылка директора несёт его область видимости, а не то, что он попросил.
     *
     * Сузить её надо в момент выдачи: потом процеживать нечем — открывающий в
     * системе не значится вовсе, и «его» подразделений не существует.
     */
    public function test_a_link_freezes_the_scope_of_the_one_who_issued_it(): void
    {
        [$retail, $wholesale] = [$this->department('Розница'), $this->department('Опт')];

        $this->hire('2026-02-01', department: $retail);
        $this->hire('2026-02-01', department: $wholesale);

        $token = $this->actingAs($this->director($retail))
            ->postJson(route('analytics.staff.links.store'), [
                'from' => self::FROM,
                'to' => self::TO,
                // Просит компанию целиком — не получит.
                'departments' => [$retail->id, $wholesale->id],
            ])
            ->assertCreated()
            ->json('data.token');

        $this->getJson(route('shared.staff-report', $token))
            ->assertOk()
            ->assertJsonPath('data.summary.hired', 1);
    }

    /** Отзыв не стирает ссылку: «была и отозвана» — тоже часть истории. */
    public function test_revoking_keeps_the_record(): void
    {
        $hr = $this->hr();

        $id = $this->actingAs($hr)
            ->postJson(route('analytics.staff.links.store'), ['from' => self::FROM, 'to' => self::TO])
            ->json('data.id');

        $this->actingAs($hr)
            ->deleteJson(route('analytics.staff.links.destroy', $id))
            ->assertOk()
            ->assertJsonPath('data.live', false);

        $this->assertNotNull(StaffReportLink::query()->findOrFail($id)->revoked_at);
    }

    /** Чужую ссылку директор не отзовёт. */
    public function test_a_director_cannot_revoke_a_stranger_link(): void
    {
        $link = StaffReportLink::query()->create([
            'created_by_id' => $this->hr()->id,
            'filters' => ['from' => self::FROM, 'to' => self::TO],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        $this->actingAs($this->director($this->department('Розница')))
            ->deleteJson(route('analytics.staff.links.destroy', $link))
            ->assertForbidden();
    }

    /* ---------- Обстановка ---------- */

    private function hr(): User
    {
        return $this->userWith(Permission::ViewWholeStaffReport);
    }

    private function director(Department $department): User
    {
        $director = $this->userWith(Permission::ViewStaffReport);
        $director->departments()->attach($department, ['role' => DepartmentRole::Head->value]);

        return $director;
    }

    private function department(string $name, ?Department $parent = null): Department
    {
        return Department::factory()->create(['name' => $name, 'parent_id' => $parent?->id]);
    }

    /**
     * Человек с кадровой историей — и сразу в подразделении опыта.
     *
     * Подразделение здесь не украшение: в базе есть и те, кого завёл сам тест —
     * кадровик, директор, — и без общего отдела они попадали бы в цифры вместе
     * с подопытными. Отчёт всегда спрашивается по отделу, и посторонние в него
     * не входят.
     */
    private function hire(
        string $hired,
        ?string $dismissed = null,
        ?Department $department = null,
        ?DismissalReason $reason = null,
    ): User {
        $person = User::factory()->create([
            'hired_at' => $hired,
            'dismissed_at' => $dismissed,
            'dismissal_reason' => $reason?->value,
        ]);

        return $this->place($person, $department ?? $this->crew());
    }

    private function place(User $person, Department $department): User
    {
        $person->departments()->attach($department, ['role' => DepartmentRole::Member->value]);

        return $person;
    }

    /** Отдел, в котором живут подопытные. */
    private function crew(): Department
    {
        return $this->crew ??= $this->department('Склад');
    }

    private ?Department $crew = null;

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function asked(string $from = self::FROM, string $to = self::TO, array $extra = []): array
    {
        return ['from' => $from, 'to' => $to, 'departments' => [$this->crew()->id], ...$extra];
    }

    private function report(string $from = self::FROM, string $to = self::TO): TestResponse
    {
        return $this->actingAs($this->hr())
            ->getJson(route('analytics.staff', $this->asked($from, $to)))
            ->assertOk();
    }
}
