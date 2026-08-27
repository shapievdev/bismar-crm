<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\News;

use App\Actions\News\GradeNewsQuizAttempt;
use App\Actions\News\SaveNewsQuiz;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Requests\Lms\SubmitQuizRequest;
use App\Http\Resources\News\NewsQuizResource;
use App\Models\News;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Проверка при новости: подтверждение того, что её прочитали.
 *
 * Разбора ошибок и статистики здесь нет — они нужны тому, кто учит. Составителю
 * важно одно: сдал человек или нет, и это видно в списке ознакомившихся.
 *
 * Проверка присланного взята у базы знаний как есть (SaveQuizRequest,
 * SubmitQuizRequest): её правила — о вопросах и вариантах, а не об уроках.
 */
final class NewsQuizController extends Controller
{
    public function save(SaveQuizRequest $request, News $news, SaveNewsQuiz $saveQuiz): NewsQuizResource
    {
        Gate::authorize('update', $news);

        /** @var array{title: string, description?: ?string, passing_score: int, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>} $attributes */
        $attributes = $request->validated();

        return NewsQuizResource::make($saveQuiz->handle($news, $attributes));
    }

    public function destroy(News $news): Response
    {
        Gate::authorize('update', $news);

        // Ознакомления, засчитанные по сданной проверке, остаются: человек
        // действительно её прошёл, и снятие теста этого не отменяет.
        $news->quiz()->delete();

        return response()->noContent();
    }

    /**
     * Пройти проверку. Сдал — новость считается прочитанной.
     *
     * @throws ConflictException
     */
    public function submit(
        SubmitQuizRequest $request,
        News $news,
        GradeNewsQuizAttempt $grade,
    ): JsonResponse {
        Gate::authorize('acknowledge', $news);

        $quiz = $news->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        /** @var User $reader */
        $reader = $request->user();

        $attempt = $grade->handle($quiz, $reader, $request->answers());

        return response()->json([
            'data' => [
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                // Сдал — значит ознакомился; экран показывает это сразу, без
                // второго запроса за новостью.
                'is_acknowledged' => $attempt->passed,
            ],
        ], HttpResponse::HTTP_CREATED);
    }
}
