<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Regulation;
use App\Models\Survey;
use App\Models\SurveyCompletion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class SurveyIndividualResponsesTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_administrator_sees_each_persons_selected_options_scale_and_text(): void
    {
        $survey = Survey::factory()->forRegulation(Regulation::factory()->published()->create())
            ->withEveryKindOfQuestion()->create();
        $questions = $survey->questions()->with('options')->orderBy('position')->get();
        $choice = $questions[0];
        $first = User::factory()->create(['last_name' => 'Иванова', 'first_name' => 'Мария']);
        $second = User::factory()->create(['last_name' => 'Петров', 'first_name' => 'Антон']);
        foreach ([$first, $second] as $i => $person) {
            SurveyCompletion::create(['survey_id' => $survey->id, 'user_id' => $person->id, 'completed_at' => now()]);
            SurveyResponse::create([
                'survey_id' => $survey->id, 'user_id' => $person->id, 'submitted_at' => now(),
                'answers' => [
                    $choice->id => ['options' => $i === 0 ? $choice->options->pluck('id')->all() : [$choice->options[1]->id], 'other' => $i === 0 ? 'Практика' : null],
                    $questions[1]->id => ['scale' => $i === 0 ? 5 : 3],
                    $questions[2]->id => $i === 0 ? ['text' => 'Больше примеров'] : [],
                ],
            ]);
        }
        $response = $this->actingAs($this->administrator())
            ->getJson(route('analytics.learning.survey', $survey))->assertOk();
        $response->assertJsonCount(2, 'data.summary.responses')
            ->assertJsonPath('data.summary.responses.0.person_id', $first->id)
            ->assertJsonPath('data.summary.responses.0.person', 'Иванова Мария')
            ->assertJsonPath('data.summary.responses.0.answers.0.options.0.text', $choice->options[0]->text)
            ->assertJsonCount(2, 'data.summary.responses.0.answers.0.options')
            ->assertJsonPath('data.summary.responses.0.answers.0.other', 'Практика')
            ->assertJsonPath('data.summary.responses.0.answers.1.scale', 5)
            ->assertJsonPath('data.summary.responses.0.answers.2.text', 'Больше примеров')
            ->assertJsonPath('data.summary.responses.1.person_id', $second->id)
            ->assertJsonCount(1, 'data.summary.responses.1.answers.0.options')
            ->assertJsonPath('data.summary.responses.1.answers.1.scale', 3)
            ->assertJsonPath('data.summary.responses.1.answers.2.text', null);
        $this->assertSame(
            $response->json('data.summary.responses.0.submitted_at'),
            collect($response->json('data.people'))->firstWhere('id', $first->id)['answered_at'],
        );
    }

    public function test_anonymous_surveys_never_expose_individual_responses_even_with_a_legacy_user_link(): void
    {
        $survey = Survey::factory()->forRegulation(Regulation::factory()->published()->create())->anonymous()->create();
        $question = $survey->questions()->create(['text' => 'Комментарий', 'type' => 'text', 'position' => 0]);
        $person = User::factory()->create(['first_name' => 'СкрытоеИмя']);
        SurveyResponse::create([
            'survey_id' => $survey->id, 'user_id' => $person->id, 'submitted_at' => now(),
            'answers' => [$question->id => ['text' => 'Анонимный ответ']],
        ]);
        $response = $this->actingAs($this->administrator())->getJson(route('analytics.learning.survey', $survey))->assertOk();
        $response->assertJsonPath('data.summary.responses', [])
            ->assertJsonPath('data.summary.questions.0.texts.0.person', null)
            ->assertJsonPath('data.summary.questions.0.texts.0.text', 'Анонимный ответ');
        $this->assertStringNotContainsString('СкрытоеИмя', $response->getContent());
    }

    public function test_deleted_people_and_options_do_not_break_saved_answers(): void
    {
        $survey = Survey::factory()->forRegulation(Regulation::factory()->published()->create())->withQuestion()->create();
        $question = $survey->questions()->firstOrFail();
        SurveyResponse::create([
            'survey_id' => $survey->id, 'user_id' => null, 'submitted_at' => now(),
            'answers' => [$question->id => ['options' => [999999]]],
        ]);
        $this->actingAs($this->administrator())->getJson(route('analytics.learning.survey', $survey))
            ->assertOk()->assertJsonPath('data.summary.responses.0.person', null)
            ->assertJsonPath('data.summary.responses.0.answers.0.options.0.text', 'Вариант удалён');
    }

    public function test_regular_readers_cannot_get_named_answers(): void
    {
        $survey = Survey::factory()->forRegulation(Regulation::factory()->published()->create())->create();
        $this->actingAs($this->learner())->getJson(route('analytics.learning.survey', $survey))->assertForbidden();
    }
}
