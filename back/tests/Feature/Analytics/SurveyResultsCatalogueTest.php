<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\Regulation;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class SurveyResultsCatalogueTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_administrators_can_browse_beyond_the_first_fifteen_surveys(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            Survey::factory()->forRegulation(Regulation::factory()->published()->create())
                ->create(['title' => sprintf('Опрос %02d', $i)]);
        }
        $this->actingAs($this->administrator())
            ->getJson(route('analytics.learning.surveys'))
            ->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2);
        $this->getJson(route('analytics.learning.surveys', ['page' => 2]))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.2.title', 'Опрос 23');
    }

    public function test_search_finds_old_and_closed_surveys_case_insensitively(): void
    {
        $survey = Survey::factory()->forRegulation(Regulation::factory()->published()->create())
            ->closed()->withQuestion()->create(['title' => 'Обратная связь о СЕРВИСЕ']);
        Survey::factory()->forRegulation(Regulation::factory()->published()->create())->create(['title' => 'Другой опрос']);
        $this->actingAs($this->superAdministrator())
            ->getJson(route('analytics.learning.surveys', ['search' => 'сервис']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $survey->id)
            ->assertJsonPath('meta.total', 1);
        $this->getJson(route('analytics.learning.survey', $survey))
            ->assertOk()->assertJsonPath('data.summary.is_open', false);
    }

    public function test_training_managers_also_have_access_but_readers_do_not(): void
    {
        $this->actingAs($this->userWith(Permission::ManageEnrollments))
            ->getJson(route('analytics.learning.surveys'))->assertOk();
        $this->actingAs($this->learner())
            ->getJson(route('analytics.learning.surveys'))->assertForbidden();
    }

    public function test_pagination_and_search_are_validated(): void
    {
        $this->actingAs($this->administrator())
            ->getJson(route('analytics.learning.surveys', ['page' => 0]))->assertUnprocessable();
        $this->getJson(route('analytics.learning.surveys', ['search' => str_repeat('x', 201)]))->assertUnprocessable();
    }
}
