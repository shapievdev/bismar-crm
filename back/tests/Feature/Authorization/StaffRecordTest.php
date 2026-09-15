<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\DismissalReason;
use App\Enums\EmploymentStatus;
use App\Enums\WorkMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Кадровое в карточке: приём, положение, режим работы, наставник, уход.
 *
 * Заведено ради аналитики штата, и потому проверяется не «поле сохраняется», а
 * то, на чём стоит отчёт: дата приёма доезжает целой, положение «уволен» не
 * подделывается формой, стаж считается от приёма до ухода, а не до сегодня.
 */
final class StaffRecordTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Поля карточки доезжают до базы и возвращаются обратно.
     */
    public function test_the_hr_fields_survive_a_round_trip(): void
    {
        $person = User::factory()->create();
        $mentor = User::factory()->create();

        $response = $this->actingAs($this->administrator())
            ->putJson(route('users.update', $person), [
                'last_name' => $person->last_name ?? 'Лавлейс',
                'first_name' => $person->first_name,
                'phone' => '+79000000042',
                'hired_at' => '2025-03-14',
                'employment_status' => EmploymentStatus::ParentalLeave->value,
                'work_mode' => WorkMode::Shift->value,
                'mentor_id' => $mentor->id,
            ])
            ->assertOk();

        $response
            ->assertJsonPath('data.hired_at', '2025-03-14')
            ->assertJsonPath('data.status', EmploymentStatus::ParentalLeave->value)
            ->assertJsonPath('data.status_label', 'В декрете')
            ->assertJsonPath('data.work_mode_label', 'Сменный')
            ->assertJsonPath('data.mentor.id', $mentor->id);

        $this->assertSame('2025-03-14', $person->refresh()->hired_at?->toDateString());
    }

    /** Пустое поле значит «убрать»: дату приёма и наставника снимают так же, как ставят. */
    public function test_clearing_a_field_removes_it(): void
    {
        $person = User::factory()->create([
            'hired_at' => '2025-03-14',
            'work_mode' => WorkMode::Office,
        ]);

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $person), [
                'last_name' => $person->last_name ?? 'Лавлейс',
                'first_name' => $person->first_name,
                'phone' => '+79000000043',
                'hired_at' => null,
                'work_mode' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.hired_at', null)
            ->assertJsonPath('data.work_mode', null);
    }

    /**
     * Уволенным формой не становятся.
     *
     * Увольнение — отдельное действие с проверкой прав и обрывом сессий; будь
     * «уволен» пунктом списка положений, его выставил бы кто угодно, у кого
     * открыта форма, и человек остался бы с действующей сессией.
     */
    public function test_the_form_cannot_dismiss_anyone(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $person), [
                'last_name' => $person->last_name ?? 'Лавлейс',
                'first_name' => $person->first_name,
                'phone' => '+79000000044',
                'employment_status' => EmploymentStatus::Dismissed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('employment_status');

        $this->assertNull($person->refresh()->dismissed_at);
    }

    /** Приём будущим днём не отмечают: человек либо принят, либо ещё нет. */
    public function test_a_hire_date_in_the_future_is_refused(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $person), [
                'last_name' => $person->last_name ?? 'Лавлейс',
                'first_name' => $person->first_name,
                'phone' => '+79000000045',
                'hired_at' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('hired_at');
    }

    /** Наставником себе не назначишь: вести себя самому не получится. */
    public function test_nobody_mentors_themselves(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $person), [
                'last_name' => $person->last_name ?? 'Лавлейс',
                'first_name' => $person->first_name,
                'phone' => '+79000000046',
                'mentor_id' => $person->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('mentor_id');
    }

    /** День приёма спрашивается сразу при заведении. */
    public function test_a_new_person_is_hired_on_a_day(): void
    {
        $id = $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'phone' => '+79000000047',
                'hired_at' => '2026-09-01',
                'work_mode' => WorkMode::Office->value,
                'password' => 'Str0ng-Passw0rd!',
            ])
            ->assertCreated()
            ->json('data.id');

        $created = User::query()->findOrFail($id);

        $this->assertSame('2026-09-01', $created->hired_at?->toDateString());
        $this->assertSame(WorkMode::Office, $created->work_mode);
    }

    /** Причина ухода записывается вместе с увольнением. */
    public function test_a_dismissal_carries_its_reason(): void
    {
        $person = User::factory()->create(['hired_at' => '2026-01-10']);

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $person), ['reason' => DismissalReason::ProbationFailed->value])
            ->assertOk()
            ->assertJsonPath('data.status', EmploymentStatus::Dismissed->value)
            ->assertJsonPath('data.dismissal_reason_label', 'Не прошёл испытательный срок');

        $this->assertSame(DismissalReason::ProbationFailed, $person->refresh()->dismissal_reason);
    }

    /** Вернувшийся — работающий сотрудник, а не «ушедший, который передумал». */
    public function test_reinstating_clears_the_reason(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $person), ['reason' => DismissalReason::Own->value])
            ->assertOk();

        $this->actingAs($this->administrator())
            ->deleteJson(route('users.reinstate', $person))
            ->assertOk()
            ->assertJsonPath('data.status', EmploymentStatus::Working->value)
            ->assertJsonPath('data.dismissal_reason', null);
    }

    /**
     * Стаж уволенного перестал расти вместе с ним.
     *
     * Считай его до сегодня — и средний срок работы ушедших рос бы сам собой,
     * без единого нового увольнения.
     */
    public function test_tenure_stops_on_the_day_someone_leaves(): void
    {
        $working = User::factory()->create(['hired_at' => now()->subMonths(10)->toDateString()]);

        $left = User::factory()->create([
            'hired_at' => now()->subMonths(10)->toDateString(),
            'dismissed_at' => now()->subMonths(4),
        ]);

        $this->assertSame(10, $working->tenureMonths());
        $this->assertSame(6, $left->tenureMonths());
    }

    /** Заведённым до того, как дату начали спрашивать, стаж не выдумывается. */
    public function test_an_unknown_hire_date_is_not_zero_tenure(): void
    {
        $this->assertNull(User::factory()->create(['hired_at' => null])->tenureMonths());
    }
}
