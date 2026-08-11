<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveQuiz;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Resources\Lms\QuizResource;
use App\Models\Lesson;
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
}
