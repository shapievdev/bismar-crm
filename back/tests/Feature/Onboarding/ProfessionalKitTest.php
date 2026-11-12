<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\ApplyTemplate;
use App\Actions\Onboarding\DuplicateTemplate;
use App\Actions\Onboarding\OpenJourney;
use App\Models\OnboardingTemplate;
use App\Support\Onboarding\ProfessionalKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class ProfessionalKitTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    private function kit(bool $optional = false): array
    {
        return [
            ['id' => 'badge', 'label' => 'Именной бейдж', 'icon' => 'badge', 'kind' => 'item', 'is_required' => true],
            ['id' => 'keys', 'label' => 'Ключ от шкафчика', 'icon' => 'key', 'kind' => 'item', 'is_required' => ! $optional],
        ];
    }

    public function test_custom_template_kit_is_copied_and_does_not_follow_later_edits(): void
    {
        $template = OnboardingTemplate::factory()->withStages(1)->create(['kit_items' => $this->kit(), 'requires_graduation' => true]);
        $employee = $this->employee();
        $journey = app(OpenJourney::class)->handle($employee);
        app(ApplyTemplate::class)->handle($journey, $template, ['anchor' => 'today'], $this->hr());
        $template->update(['kit_items' => []]);
        $this->assertSame($this->kit(), $journey->fresh()->kit_items);
        $copy = app(DuplicateTemplate::class)->handle($template, $this->hr());
        $this->assertSame([], $copy->kit_items);
    }

    public function test_personal_custom_items_complete_the_route_without_legacy_seller_items(): void
    {
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => $this->kit(), 'requires_graduation' => true]);
        $hr = $this->hr();
        $url = route('onboarding.manage.employees.graduate', $employee);
        $this->actingAs($hr)->postJson($url, ['what' => 'badge'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.graduation.items.0.confirmed_by', $hr->id);
        $this->postJson($url, ['what' => 'keys'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.graduation.fully_equipped', true);
        $this->assertNull($journey->fresh()->access_confirmed_at);
        $this->assertNull($journey->fresh()->tshirt_issued_at);
        $this->postJson($url, ['what' => 'keys'])->assertConflict();
        $this->postJson($url, ['what' => 'tshirt'])->assertConflict();
    }

    public function test_optional_item_does_not_block_completion_and_can_be_received_later(): void
    {
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => $this->kit(true), 'requires_graduation' => true]);
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'badge'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.graduation.items.1.confirmed_at', null);
        $this->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'keys'])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_legacy_receipts_keep_dates_and_issuer_after_migration(): void
    {
        [$journey] = $this->journeyFor($this->employee(), stages: 1);
        $hr = $this->hr();
        $journey->fill(['access_confirmed_at' => '2026-11-01 10:00:00', 'access_confirmed_by' => $hr->id,
            'tshirt_issued_at' => '2026-11-01 11:00:00', 'tshirt_issued_by' => $hr->id,
            'barcode_issued_at' => '2026-11-01 12:00:00', 'barcode_issued_by' => $hr->id])->save();
        $items = ProfessionalKit::presented($journey->fresh());
        $this->assertCount(3, $items);
        $this->assertSame('2026-11-01T10:00:00+00:00', $items[0]['confirmed_at']);
        $this->assertSame($hr->id, $items[0]['confirmed_by']);
        $this->assertSame($hr->name, $items[0]['confirmed_by_name']);
        $this->assertTrue($journey->fresh()->isFullyEquipped());
    }

    public function test_custom_admission_is_checked_before_issuing_equipment_and_keeps_access_report(): void
    {
        $employee = $this->employee();
        $kit = [['id' => 'permit', 'label' => 'Допуск к работе на складе', 'icon' => 'access', 'kind' => 'admission', 'is_required' => true], ...$this->kit()];
        [$journey] = $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => $kit, 'requires_graduation' => true], prepare: fn ($template) => $this->quizFor($template->stages()->first()));
        $stage = $journey->stages->first();
        $this->actingAs($employee)->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($this->journeyQuizOf($stage))])->assertCreated();
        $hr = $this->hr();
        $url = route('onboarding.manage.employees.graduate', $employee);
        $this->actingAs($hr)->postJson($url, ['what' => 'badge'])->assertConflict();
        $this->postJson($url, ['what' => 'permit'])->assertOk();
        $this->assertNotNull($journey->fresh()->access_confirmed_at);
        $this->assertSame($hr->id, $journey->fresh()->access_confirmed_by);
        $this->postJson($url, ['what' => 'badge'])->assertOk();
    }

    public function test_issued_items_cannot_be_removed_or_renamed_by_settings_or_forged_input(): void
    {
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => $this->kit(), 'requires_graduation' => true]);
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'badge'])->assertOk();
        $url = route('onboarding.manage.employees.settings', $employee);
        $this->putJson($url, ['kit_items' => [$this->kit()[1]]])->assertUnprocessable()->assertJsonValidationErrors('kit_items');
        $renamed = $this->kit();
        $renamed[0]['label'] = 'Другой предмет';
        $this->putJson($url, ['kit_items' => $renamed])->assertUnprocessable();
        $this->putJson($url, ['kit_receipts' => ['keys' => ['confirmed_at' => now()->toIso8601String()]]])->assertOk()->assertJsonPath('data.graduation.items.1.confirmed_at', null);
    }

    public function test_adding_a_required_item_to_completed_route_reopens_it_and_preserves_receipts(): void
    {
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => [$this->kit()[0]], 'requires_graduation' => true]);
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'badge'])->assertJsonPath('data.status', 'completed');
        $this->putJson(route('onboarding.manage.employees.settings', $employee), ['kit_items' => $this->kit()])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.completed_at', null)->assertJsonPath('data.graduation.items.0.label', 'Именной бейдж');
        $this->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'keys'])->assertJsonPath('data.status', 'completed');
    }

    public function test_kit_validation_rejects_duplicate_ids_multiple_admissions_and_empty_required_kit(): void
    {
        $template = OnboardingTemplate::factory()->create();
        $this->actingAs($this->hr());
        $url = route('onboarding.manage.templates.update', $template);
        $this->putJson($url, ['title' => 'Набор', 'kit_items' => [$this->kit()[0], $this->kit()[0]]])->assertUnprocessable();
        $this->putJson($url, ['title' => 'Набор', 'requires_graduation' => true, 'kit_items' => []])->assertUnprocessable()->assertJsonValidationErrors('kit_items');
        $admissions = $this->kit();
        foreach ($admissions as &$item) {
            $item['kind'] = 'admission';
        }
        $this->putJson($url, ['title' => 'Набор', 'kit_items' => $admissions])->assertUnprocessable();
        $this->putJson($url, ['title' => 'Набор', 'requires_graduation' => false, 'kit_items' => []])->assertOk()->assertJsonPath('data.kit_items', []);
    }

    public function test_user_cannot_change_or_issue_their_own_kit(): void
    {
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, templateState: ['kit_items' => $this->kit(), 'requires_graduation' => true]);
        $this->actingAs($employee)->putJson(route('onboarding.manage.employees.settings', $employee), ['kit_items' => []])->assertForbidden();
        $this->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => 'badge'])->assertForbidden();
        $this->getJson(route('onboarding.my'))->assertOk()->assertJsonPath('data.graduation.items.0.label', 'Именной бейдж');
    }

    public function test_first_template_cannot_replace_historical_items_of_an_empty_route(): void
    {
        $employee = $this->employee();
        $journey = app(OpenJourney::class)->handle($employee);
        $journey->fill(['access_confirmed_at' => now(), 'tshirt_issued_at' => now(), 'barcode_issued_at' => now()])->save();
        $template = OnboardingTemplate::factory()->withStages(1)->create(['kit_items' => $this->kit()]);
        app(ApplyTemplate::class)->handle($journey, $template, ['anchor' => 'today'], $this->hr());
        $this->assertSame(ProfessionalKit::defaults(), $journey->fresh()->kit_items);
        $this->assertTrue($journey->fresh()->isFullyEquipped());
    }
}
