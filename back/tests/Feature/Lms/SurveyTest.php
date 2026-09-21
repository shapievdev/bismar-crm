<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Enums\SurveyQuestionType;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\Survey;
use App\Models\SurveyCompletion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Опросник при материале: как его заводят, как проходят и что видно в сводке.
 *
 * Проверяется то, чем опрос отличается от теста, — и отличий три. Проходят его
 * один раз и никогда больше. Правильного ответа у него нет, и потому нечего
 * скрывать от того, кто отвечает. И он бывает анонимным — а это обещание,
 * которое обязано держаться в самой схеме, а не в намерениях выдачи.
 *
 * Обязательность разобрана отдельно, в RequiredSurveyTest: там она про зачёт
 * материала, а здесь про сам опрос.
 */
final class SurveyTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function lesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create(['module_id' => $module->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Что скажете об уроке?',
            'description' => 'Ответы читает автор.',
            'is_required' => false,
            'is_anonymous' => false,
            'questions' => [
                [
                    'text' => 'Пригодилось?',
                    'type' => SurveyQuestionType::Single->value,
                    'is_required' => true,
                    'options' => [['text' => 'Да'], ['text' => 'Нет']],
                ],
            ],
        ], $overrides);
    }

    /* ---------- Как заводят ---------- */

    public function test_an_author_sets_up_a_survey_on_a_lesson(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.survey.save', $lesson), $this->payload([
                'is_required' => true,
                'is_anonymous' => true,
                'thanks' => 'Спасибо, разберём на планёрке.',
            ]))
            // 201, а не 200: опроса при уроке ещё не было, и ресурс говорит
            // «создано» — как и ресурс теста.
            ->assertCreated()
            ->assertJsonPath('data.title', 'Что скажете об уроке?')
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.thanks', 'Спасибо, разберём на планёрке.')
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonCount(2, 'data.questions.0.options');
    }

    /** Один опрос на материал: второй вопрос «что вы думаете» сбивал бы с толку. */
    public function test_saving_twice_replaces_the_survey_rather_than_adding_one(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)->putJson(route('lms.survey.save', $lesson), $this->payload())->assertCreated();

        // Второе сохранение правит тот же опрос, а не заводит второй.
        $this->actingAs($author)
            ->putJson(route('lms.survey.save', $lesson), $this->payload(['title' => 'Переписанный']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Переписанный');

        $this->assertSame(1, Survey::query()->count());
    }

    /**
     * Номер вопроса — то, чем он остаётся собой: по нему разложены снимки
     * ответов, и пересозданный вопрос отвязал бы от себя всё, что люди сказали.
     */
    public function test_editing_keeps_the_question_it_was_given(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $created = $this->actingAs($author)
            ->putJson(route('lms.survey.save', $lesson), $this->payload())
            ->assertCreated()
            ->json('data.questions.0.id');

        $this->actingAs($author)
            ->putJson(route('lms.survey.save', $lesson), $this->payload([
                'questions' => [[
                    'id' => $created,
                    'text' => 'Пригодилось ли?',
                    'type' => SurveyQuestionType::Single->value,
                    'options' => [['text' => 'Да'], ['text' => 'Нет']],
                ]],
            ]))
            ->assertOk()
            ->assertJsonPath('data.questions.0.id', $created)
            ->assertJsonPath('data.questions.0.text', 'Пригодилось ли?');
    }

    public function test_a_learner_cannot_set_up_a_survey(): void
    {
        $this->actingAs($this->learner())
            ->putJson(route('lms.survey.save', $this->lesson()), $this->payload())
            ->assertForbidden();
    }

    /** Из одного варианта не выбирают. */
    public function test_a_choice_needs_at_least_two_options(): void
    {
        $this->actingAs($this->author())
            ->putJson(route('lms.survey.save', $this->lesson()), $this->payload([
                'questions' => [[
                    'text' => 'Пригодилось?',
                    'type' => SurveyQuestionType::Single->value,
                    'options' => [['text' => 'Да']],
                ]],
            ]))
            ->assertJsonValidationErrors('questions.0.options');
    }

    /** Шкала в одно деление — не шкала. */
    public function test_a_scale_needs_at_least_two_steps(): void
    {
        $this->actingAs($this->author())
            ->putJson(route('lms.survey.save', $this->lesson()), $this->payload([
                'questions' => [[
                    'text' => 'Насколько понятно?',
                    'type' => SurveyQuestionType::Scale->value,
                    'scale_min' => 3,
                    'scale_max' => 3,
                ]],
            ]))
            ->assertJsonValidationErrors('questions.0.scale_max');
    }

    /* ---------- Как проходят ---------- */

    public function test_a_survey_is_answered_once_and_never_again(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();
        $learner = $this->learner();

        $option = $survey->questions()->first()?->options()->first();

        $this->actingAs($learner)
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$survey->questions()->first()?->getKey() => ['options' => [$option?->getKey()]]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.submitted_at', fn ($value): bool => $value !== null);

        // Второй раз — отказ, и мнение остаётся одно.
        $this->actingAs($learner)
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$survey->questions()->first()?->getKey() => ['options' => [$option?->getKey()]]],
            ])
            ->assertStatus(409);

        $this->assertSame(1, SurveyResponse::query()->count());
        $this->assertSame(1, SurveyCompletion::query()->count());
    }

    /** Обязательный вопрос внутри опроса пропустить нельзя. */
    public function test_a_required_question_must_be_answered(): void
    {
        $lesson = $this->lesson();
        Survey::factory()->forLesson($lesson)->withQuestion()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.survey.submit', $lesson), ['answers' => []])
            ->assertJsonValidationErrors('answers');
    }

    /** Необязательный — можно: его на то и пометили. */
    public function test_an_optional_question_may_be_skipped(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->create();
        $survey->questions()->create([
            'text' => 'Что улучшить?',
            'type' => SurveyQuestionType::LongText,
            'is_required' => false,
            'position' => 0,
        ]);

        $this->actingAs($this->learner())
            ->postJson(route('lms.survey.submit', $lesson), ['answers' => []])
            ->assertCreated();

        $this->assertSame([], SurveyResponse::query()->firstOrFail()->answers);
    }

    /** Закрытый по сроку опрос ответов не принимает. */
    public function test_a_closed_survey_takes_no_answers(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->closed()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$survey->questions()->first()?->getKey() => ['options' => [1]]],
            ])
            ->assertStatus(409);
    }

    /**
     * Деление, которого у шкалы нет, ответом не становится: форма такого не
     * пришлёт, а запрос приходит не только из формы.
     */
    public function test_a_step_outside_the_scale_is_not_an_answer(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->create();
        $question = $survey->questions()->create([
            'text' => 'Насколько понятно?',
            'type' => SurveyQuestionType::Scale,
            'is_required' => false,
            'scale_min' => 1,
            'scale_max' => 5,
            'position' => 0,
        ]);

        $this->actingAs($this->learner())
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$question->getKey() => ['scale' => 9]],
            ])
            ->assertCreated();

        $this->assertSame([], SurveyResponse::query()->firstOrFail()->answers);
    }

    /** Чужой вариант — не ответ: он из другого вопроса. */
    public function test_an_option_from_another_question_is_dropped(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();
        $question = $survey->questions()->firstOrFail();

        $strangerSurvey = Survey::factory()->forLesson($this->lesson())->withQuestion()->create();
        $stranger = $strangerSurvey->questions()->firstOrFail()->options()->firstOrFail();

        $this->actingAs($this->learner())
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$question->getKey() => ['options' => [$stranger->getKey()]]],
            ])
            // Обязательный вопрос остался без ответа: чужой вариант отброшен.
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_guest_answers_nothing(): void
    {
        $lesson = $this->lesson();
        Survey::factory()->forLesson($lesson)->withQuestion()->create();

        $this->postJson(route('lms.survey.submit', $lesson), ['answers' => []])
            ->assertUnauthorized();
    }

    /* ---------- Анонимность ---------- */

    /**
     * Анонимность — обещание, и держится оно схемой: в строке с ответами
     * человека не остаётся вовсе. Отметка о прохождении при этом есть — иначе
     * обязательный опрос нечем закрыть.
     */
    public function test_an_anonymous_survey_keeps_no_name_beside_the_answers(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->anonymous()->withQuestion()->create();
        $question = $survey->questions()->firstOrFail();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$question->getKey() => ['options' => [$question->options()->firstOrFail()->getKey()]]],
            ])
            ->assertCreated();

        $this->assertNull(SurveyResponse::query()->firstOrFail()->user_id);
        $this->assertSame(
            $learner->getKey(),
            SurveyCompletion::query()->firstOrFail()->user_id,
        );
    }

    public function test_a_named_survey_keeps_the_name(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();
        $question = $survey->questions()->firstOrFail();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.survey.submit', $lesson), [
                'answers' => [$question->getKey() => ['options' => [$question->options()->firstOrFail()->getKey()]]],
            ])
            ->assertCreated();

        $this->assertSame($learner->getKey(), SurveyResponse::query()->firstOrFail()->user_id);
    }

    /* ---------- Сводка ---------- */

    public function test_the_summary_counts_answers_and_reads_the_written_ones(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withEveryKindOfQuestion()->create();

        [$choice, $scale, $written] = $survey->questions()->get()->all();

        $first = $this->learner();
        $second = $this->learner();

        $this->actingAs($first)->postJson(route('lms.survey.submit', $lesson), [
            'answers' => [
                $choice->getKey() => [
                    'options' => [$choice->options()->firstOrFail()->getKey()],
                    'other' => 'И разбор возражений',
                ],
                $scale->getKey() => ['scale' => 5],
                $written->getKey() => ['text' => 'Побольше примеров'],
            ],
        ])->assertCreated();

        $this->actingAs($second)->postJson(route('lms.survey.submit', $lesson), [
            'answers' => [
                $choice->getKey() => ['options' => [$choice->options()->firstOrFail()->getKey()]],
                $scale->getKey() => ['scale' => 3],
            ],
        ])->assertCreated();

        $summary = $this->actingAs($this->author())
            ->getJson(route('lms.survey.summary', $lesson))
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $summary['answered']);

        // Выбор: оба отметили первый вариант, второй не отметил никто.
        $this->assertSame(2, $summary['questions'][0]['options'][0]['count']);
        $this->assertSame(100, $summary['questions'][0]['options'][0]['share']);
        $this->assertSame(0, $summary['questions'][0]['options'][1]['count']);
        $this->assertSame('И разбор возражений', $summary['questions'][0]['other'][0]['text']);

        // Шкала: распределение по делениям и среднее.
        $this->assertSame(5, count($summary['questions'][1]['scale']['steps']));
        // Сравнение нестрогое: json отдаёт круглое среднее целым числом.
        $this->assertEquals(4.0, $summary['questions'][1]['scale']['average']);

        // Письменное — как написано, целиком.
        $this->assertSame('Побольше примеров', $summary['questions'][2]['texts'][0]['text']);
        $this->assertSame(1, $summary['questions'][2]['answered']);
    }

    /** У анонимного опроса в сводке нет имён — и сказано об этом прямо. */
    public function test_the_summary_of_an_anonymous_survey_has_no_names(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->anonymous()->create();
        $question = $survey->questions()->create([
            'text' => 'Что улучшить?',
            'type' => SurveyQuestionType::LongText,
            'is_required' => true,
            'position' => 0,
        ]);

        $this->actingAs($this->learner())->postJson(route('lms.survey.submit', $lesson), [
            'answers' => [$question->getKey() => ['text' => 'Ничего, всё понятно']],
        ])->assertCreated();

        $summary = $this->actingAs($this->author())
            ->getJson(route('lms.survey.summary', $lesson))
            ->assertOk()
            ->json('data');

        $this->assertTrue($summary['is_anonymous']);
        $this->assertSame('Ничего, всё понятно', $summary['questions'][0]['texts'][0]['text']);
        $this->assertNull($summary['questions'][0]['texts'][0]['person']);
    }

    /** Сводка — тому, кто ведёт материал: это его работа, а не чтение чужих мнений. */
    public function test_a_learner_cannot_read_the_summary(): void
    {
        $lesson = $this->lesson();
        Survey::factory()->forLesson($lesson)->withQuestion()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.survey.summary', $lesson))
            ->assertForbidden();
    }

    /* ---------- Все четыре материала ---------- */

    public function test_a_document_carries_a_survey_too(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.survey.save', $document), $this->payload())
            ->assertCreated();

        $survey = $document->survey()->with('questions.options')->firstOrFail();
        $question = $survey->questions->firstOrFail();

        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.survey.submit', $document), [
                'answers' => [$question->getKey() => ['options' => [$question->options->firstOrFail()->getKey()]]],
            ])
            ->assertCreated();
    }

    public function test_a_handbook_carries_a_survey_too(): void
    {
        $handbook = Regulation::factory()->published()->handbook()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.handbooks.survey.save', $handbook), $this->payload())
            ->assertCreated();

        $this->assertSame(1, $handbook->survey()->count());
    }

    public function test_a_news_item_carries_a_survey_too(): void
    {
        $news = News::factory()->create([
            'status' => NewsStatus::Published,
            'published_at' => now(),
        ]);

        $editor = User::factory()->create();
        $editor->givePermissionTo(Permission::ManageNews->value);

        $this->actingAs($editor)
            ->putJson(route('news.survey.save', $news), $this->payload())
            ->assertCreated();

        $survey = $news->survey()->with('questions.options')->firstOrFail();
        $question = $survey->questions->firstOrFail();

        $this->actingAs($this->learner())
            ->postJson(route('news.survey.submit', $news), [
                'answers' => [$question->getKey() => ['options' => [$question->options->firstOrFail()->getKey()]]],
            ])
            ->assertCreated();
    }

    /** Снятый опрос уносит ответы: они разложены по номерам его вопросов. */
    public function test_removing_a_survey_removes_what_was_said(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->withQuestion()->create();
        $question = $survey->questions()->firstOrFail();

        $this->actingAs($this->learner())->postJson(route('lms.survey.submit', $lesson), [
            'answers' => [$question->getKey() => ['options' => [$question->options()->firstOrFail()->getKey()]]],
        ])->assertCreated();

        $this->actingAs($this->author())
            ->deleteJson(route('lms.survey.destroy', $lesson))
            ->assertNoContent();

        $this->assertSame(0, Survey::query()->count());
        $this->assertSame(0, SurveyResponse::query()->count());
        $this->assertSame(0, SurveyCompletion::query()->count());
    }
}
