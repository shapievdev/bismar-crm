<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveQuiz;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\Lesson;
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

    /**
     * Как тест проходят: что заваливают и какой неверный вариант выбирают.
     *
     * Единственное место, где урок сам сообщает о своей дыре: вопрос, который
     * не даётся почти никому, обычно разобран в уроке плохо или не разобран
     * вовсе.
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
     * Разбор чужой попытки — тому, кто ведёт урок.
     *
     * Общая доля по вопросу говорит, что материал не дошёл; отправленное одним
     * человеком говорит, как именно он его понял, — а это разные разговоры, и
     * второй ведут по конкретной попытке.
     *
     * Адрес при уроке, а не при попытке: право смотреть чужие ответы даёт
     * материал, у него оно и спрашивается — маршрут закрыт правом на правку.
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
