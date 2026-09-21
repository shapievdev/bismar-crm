<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\NewsStatus;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Quiz;
use App\Models\Regulation;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Обязательный опрос держит зачёт материала.
 *
 * Решение пользователя 2026-09-21: урок не считается пройденным, документ и
 * справочник — ознакомленными, новость — подтверждённой, пока обязательный опрос
 * не отправлен. Необязательный не держит ничего.
 *
 * Отдельным файлом от SurveyTest, потому что проверяется здесь не опрос, а зачёт:
 * четыре материала, три способа отметиться и один случай, из-за которого всё это
 * и переехало в одно место, — сдавший тест, но не высказавшийся. Пока зачёт стоял
 * там же, где оценивалась попытка, такой человек оставался бы незачтённым
 * навсегда: второй попытки у теста может и не быть.
 */
final class RequiredSurveyTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function lesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create(['module_id' => $module->id]);
    }

    /**
     * Отправка опроса с одним отмеченным вариантом — то, чем в этих проверках
     * закрывают требование.
     */
    private function answer(Survey $survey, string $route, mixed $owner, User $reader): void
    {
        $question = $survey->questions()->with('options')->firstOrFail();

        $this->actingAs($reader)
            ->postJson(route($route, $owner), [
                'answers' => [$question->getKey() => ['options' => [$question->options->firstOrFail()->getKey()]]],
            ])
            ->assertCreated();
    }

    /* ---------- Урок ---------- */

    public function test_a_lesson_is_not_completed_until_the_required_survey_is_answered(): void
    {
        $lesson = $this->lesson();
        $survey = Survey::factory()->forLesson($lesson)->required()->withQuestion()->create();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'опрос'));

        $this->answer($survey, 'lms.survey.submit', $lesson, $learner);

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertOk();
    }

    /** Необязательный опрос зачёт не держит: его на то и пометили. */
    public function test_an_optional_survey_holds_nothing(): void
    {
        $lesson = $this->lesson();
        Survey::factory()->forLesson($lesson)->withQuestion()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertOk();
    }

    /**
     * Опрос, у которого вышел срок, не держит ничего: срок вышел не по вине
     * читателя, и запирать за ним урок значило бы запереть его навсегда.
     */
    public function test_a_closed_survey_holds_nothing(): void
    {
        $lesson = $this->lesson();
        Survey::factory()->forLesson($lesson)->required()->closed()->withQuestion()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertOk();
    }

    /**
     * Урок с тестом и опросом: отправленный опрос закрывает его сам, не заставляя
     * человека возвращаться за кнопкой, которой при тесте и не рисуют.
     */
    public function test_answering_the_survey_closes_a_lesson_whose_quiz_is_already_passed(): void
    {
        $lesson = $this->lesson();
        $quiz = Quiz::factory()->forLesson($lesson)->withQuestions(1)->create();
        $survey = Survey::factory()->forLesson($lesson)->required()->withQuestion()->create();
        $learner = $this->learner();

        $question = $quiz->questions()->with('options')->firstOrFail();
        $correct = $question->options->firstWhere('is_correct', true);

        // Тест сдан — но урок пока не зачтён: ждёт мнения.
        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), [
                'answers' => [$question->getKey() => [$correct?->getKey()]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->assertFalse($this->hasCompleted($learner, $lesson));

        $this->answer($survey, 'lms.survey.submit', $lesson, $learner);

        // Отправленный опрос и закрыл урок — сам.
        $this->assertTrue($this->hasCompleted($learner, $lesson));
    }

    private function hasCompleted(User $learner, Lesson $lesson): bool
    {
        return $lesson->loadMissing('module.course')->module?->course
            ?->enrollments()
            ->where('user_id', $learner->getKey())
            ->first()
            ?->completions()
            ->where('lesson_id', $lesson->getKey())
            ->exists() ?? false;
    }

    /* ---------- Документ и справочник ---------- */

    public function test_a_document_is_not_acknowledged_until_the_required_survey_is_answered(): void
    {
        $document = Regulation::factory()->published()->create();
        $survey = Survey::factory()->forRegulation($document)->required()->withQuestion()->create();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.documents.acknowledge', $document))
            ->assertStatus(409);

        $this->answer($survey, 'lms.documents.survey.submit', $document, $reader);

        $this->actingAs($reader)
            ->postJson(route('lms.documents.acknowledge', $document))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);
    }

    /**
     * Документ без проверки: опрос и есть то последнее, чего он ждал, — отметка
     * ставится сразу, и второй кнопки для этого не нужно.
     */
    public function test_answering_the_survey_acknowledges_a_document_with_no_quiz(): void
    {
        $document = Regulation::factory()->published()->create();
        $survey = Survey::factory()->forRegulation($document)->required()->withQuestion()->create();
        $reader = $this->learner();

        $this->answer($survey, 'lms.documents.survey.submit', $document, $reader);

        $this->assertTrue(
            $document->acknowledgements()->where('user_id', $reader->getKey())->exists(),
        );
    }

    /**
     * Документ с проверкой и опросом — тот самый случай, ради которого зачёт и
     * переехал в одно место: сдача теста ознакомления ещё не даёт.
     */
    public function test_a_document_waits_for_both_the_quiz_and_the_survey(): void
    {
        $document = Regulation::factory()->published()->create();
        $quiz = Quiz::factory()->forRegulation($document)->withQuestions(1)->create();
        $survey = Survey::factory()->forRegulation($document)->required()->withQuestion()->create();
        $reader = $this->learner();

        $question = $quiz->questions()->with('options')->firstOrFail();
        $correct = $question->options->firstWhere('is_correct', true);

        $this->actingAs($reader)
            ->postJson(route('lms.documents.quiz.submit', $document), [
                'answers' => [$question->getKey() => [$correct?->getKey()]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            // Сдал, но пока не ознакомлен: документ ждёт ещё и мнения.
            ->assertJsonPath('data.is_acknowledged', false);

        $this->assertFalse($document->acknowledgements()->where('user_id', $reader->getKey())->exists());

        $this->answer($survey, 'lms.documents.survey.submit', $document, $reader);

        $this->assertTrue($document->acknowledgements()->where('user_id', $reader->getKey())->exists());
    }

    public function test_a_handbook_waits_for_its_required_survey_too(): void
    {
        $handbook = Regulation::factory()->published()->handbook()->create();
        $survey = Survey::factory()->forRegulation($handbook)->required()->withQuestion()->create();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.handbooks.acknowledge', $handbook))
            ->assertStatus(409);

        $this->answer($survey, 'lms.handbooks.survey.submit', $handbook, $reader);

        $this->actingAs($reader)
            ->postJson(route('lms.handbooks.acknowledge', $handbook))
            ->assertOk();
    }

    /* ---------- Новость ---------- */

    public function test_a_news_item_is_not_acknowledged_until_the_required_survey_is_answered(): void
    {
        $news = News::factory()->create([
            'status' => NewsStatus::Published,
            'published_at' => now(),
            'requires_acknowledgement' => true,
        ]);

        $survey = Survey::factory()->forNews($news)->required()->withQuestion()->create();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('news.acknowledge', $news))
            ->assertStatus(409);

        $this->answer($survey, 'news.survey.submit', $news, $reader);

        $this->actingAs($reader)
            ->postJson(route('news.acknowledge', $news))
            ->assertOk();
    }

    /**
     * Новость без проверки: прошедший опрос подтверждён им же — и в журнале
     * ознакомлений сказано именно это, а не «подтвердил».
     */
    public function test_answering_the_survey_acknowledges_a_news_item_and_says_so(): void
    {
        $news = News::factory()->create([
            'status' => NewsStatus::Published,
            'published_at' => now(),
            'requires_acknowledgement' => true,
        ]);

        $survey = Survey::factory()->forNews($news)->required()->withQuestion()->create();
        $reader = $this->learner();

        $this->answer($survey, 'news.survey.submit', $news, $reader);

        $acknowledgement = $news->acknowledgements()->where('user_id', $reader->getKey())->firstOrFail();

        $this->assertSame('survey', $acknowledgement->source->value);
    }
}
