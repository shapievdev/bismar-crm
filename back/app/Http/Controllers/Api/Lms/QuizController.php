<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveQuiz;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\Lesson;
use App\Models\MaterialVersion;
use App\Models\QuizAttempt;
use App\Support\Lms\QuizReview;
use App\Support\Lms\QuizStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class QuizController extends Controller
{
    /**
     * Creates or replaces the lesson's quiz. The editor always sends the whole
     * thing, so there is one endpoint rather than a create/update pair.
     *
     * @param  array{title: string, description?: ?string, passing_score: int, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>}  $attributes
     */
    public function save(SaveQuizRequest $request, Lesson $lesson, SaveQuiz $saveQuiz): QuizResource
    {
        /** @var array{title: string, description?: ?string, passing_score: int, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($saveQuiz->handle($lesson, $attributes));
    }

    public function destroy(Lesson $lesson): Response
    {
        $lesson->loadMissing('quiz')->quiz?->delete();

        return response()->noContent();
    }

    /* ---------- То же, но при версии урока (2026-09-25) ---------- */

    /*
     * Версия — часть урока, а не отдельный материал: права спрашиваются у
     * курса маршрутом, а меняется лишь то, при чём стоит тест. Поэтому здесь
     * короткие двойники, а не второй контроллер — устройство теста от владельца
     * не зависит, см. RegulationQuizController.
     */

    public function saveForVersion(
        SaveQuizRequest $request,
        Lesson $lesson,
        MaterialVersion $version,
        SaveQuiz $saveQuiz,
    ): QuizResource {
        $this->ensureBelongs($lesson, $version);

        /** @var array{title: string, description?: ?string, passing_score: int, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($saveQuiz->handle($version, $attributes));
    }

    public function destroyForVersion(Lesson $lesson, MaterialVersion $version): Response
    {
        $this->ensureBelongs($lesson, $version);

        $version->quiz()->delete();

        return response()->noContent();
    }

    public function statisticsForVersion(
        Lesson $lesson,
        MaterialVersion $version,
        QuizStatistics $statistics,
    ): JsonResponse {
        $this->ensureBelongs($lesson, $version);

        $quiz = $version->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        return response()->json(['data' => $statistics->of($quiz)]);
    }

    public function attemptForVersion(
        Lesson $lesson,
        MaterialVersion $version,
        QuizAttempt $attempt,
        QuizReview $review,
    ): QuizAttemptResource {
        $this->ensureBelongs($lesson, $version);

        $quiz = $version->quiz;

        abort_if(
            $quiz === null || $attempt->quiz_id !== $quiz->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $attempt->setAttribute('review', $review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Версия чужого урока — тот же случай, что и её отсутствие.
     */
    private function ensureBelongs(Lesson $lesson, MaterialVersion $version): void
    {
        abort_unless($version->belongsToMaterial($lesson), HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Как тест проходят: что заваливают и какой неверный вариант выбирают.
     *
     * Единственное место, где урок сам сообщает о своей дыре: вопрос, который
     * не даётся почти никому, обычно разобран в уроке плохо или не разобран
     * вовсе.
     *
     * Смотрит администратор: с 2026-09-12 разбор стоит не в редакторе урока, а
     * на его странице, рядом с остальной статистикой прохождения, и закрыт тем
     * же средством — см. routes/api.php.
     */
    public function statistics(Lesson $lesson, QuizStatistics $statistics): JsonResponse
    {
        $quiz = $lesson->loadMissing('quiz')->quiz;

        if ($quiz === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $statistics->of($quiz)]);
    }

    /**
     * Разбор чужой попытки — администратору.
     *
     * Общая доля по вопросу говорит, что материал не дошёл; отправленное одним
     * человеком говорит, как именно он его понял, — а это разные разговоры, и
     * второй ведут по конкретной попытке.
     *
     * Адрес при уроке, а не при попытке: попытка сама по себе не знает, чей
     * материал она проверяет, и без урока её нечем сверить с тестом.
     */
    public function attempt(Lesson $lesson, QuizAttempt $attempt, QuizReview $review): QuizAttemptResource
    {
        $quiz = $lesson->loadMissing('quiz')->quiz;

        // Попытка не от этого теста — тот же случай, что и её отсутствие:
        // правом на свой урок чужой не открыть.
        abort_if(
            $quiz === null || $attempt->quiz_id !== $quiz->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $attempt->setAttribute('review', $review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt);
    }
}
