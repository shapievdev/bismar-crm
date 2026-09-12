<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\GradeQuizAttempt;
use App\Actions\Lms\SaveQuiz;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Requests\Lms\SubmitQuizRequest;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\Lms\MaterialVersions;
use App\Support\Lms\QuizReview;
use App\Support\Lms\QuizStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Проверка при документе.
 *
 * Есть проверка — значит ознакомление засчитывается сдачей, а не нажатием
 * кнопки: сначала прочитал, потом ответил, и только тогда числится
 * ознакомленным (решение пользователя 2026-09-01).
 *
 * Устройство теста то же, что у урока, и потому взято как есть — вместе с
 * проверкой присланного, оценкой и разбором. Разное у них ровно одно: что
 * означает сдача, и это знает GradeQuizAttempt.
 */
final class RegulationQuizController extends Controller
{
    public function __construct(private readonly QuizReview $review) {}

    public function save(SaveQuizRequest $request, Regulation $regulation, SaveQuiz $saveQuiz): QuizResource
    {
        Gate::authorize('update', $regulation);

        /** @var array{title: string, description?: ?string, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($saveQuiz->handle($regulation, $attributes));
    }

    public function destroy(Regulation $regulation): Response
    {
        Gate::authorize('update', $regulation);

        // Ознакомления, засчитанные по сданной проверке, остаются: человек
        // действительно её прошёл, и снятие теста этого не отменяет.
        $regulation->quiz()->delete();

        return response()->noContent();
    }

    /**
     * Какие вопросы проверки заваливают. Разбор тот же, что у теста урока, —
     * считать его дважды незачем, см. QuizStatistics.
     *
     * Смотрит администратор: с 2026-09-12 это часть статистики прохождения, и
     * закрыта она положением, а не правом на правку, — см. routes/api.php.
     * Проверка прав ниже при этом остаётся: маршрут говорит, кого пускать в
     * раздел, политика — чей это документ.
     */
    public function statistics(Regulation $regulation, QuizStatistics $statistics): JsonResponse
    {
        Gate::authorize('update', $regulation);

        $quiz = $regulation->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        return response()->json(['data' => $statistics->of($quiz)]);
    }

    /**
     * Разбор чужой попытки — администратору. Устроен так же, как у урока,
     * см. QuizController::attempt.
     */
    public function attempt(Regulation $regulation, QuizAttempt $attempt): QuizAttemptResource
    {
        Gate::authorize('update', $regulation);

        $quiz = $regulation->quiz;

        abort_if(
            $quiz === null || $attempt->quiz_id !== $quiz->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $attempt->setAttribute('review', $this->review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Пройти проверку. Сдал — документ считается прочитанным.
     *
     * @throws ConflictException
     */
    public function submit(
        SubmitQuizRequest $request,
        Regulation $regulation,
        GradeQuizAttempt $grade,
    ): JsonResponse {
        Gate::authorize('acknowledge', $regulation);

        $quiz = $regulation->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        /** @var User $reader */
        $reader = $request->user();

        // Записи на документ не бывает — проходить в нём нечего, — поэтому
        // третьим доводом идёт null: сдача здесь означает ознакомление.
        $attempt = $grade->handle($quiz, $reader, $request->answers(), null);

        return response()->json([
            'data' => [
                'id' => $attempt->getKey(),
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),

                // Сдал — значит ознакомился; экран показывает это сразу, без
                // второго запроса за документом.
                'is_acknowledged' => $attempt->passed,

                // Разбор прикладывается сразу: человек хочет знать, где ошибся,
                // ровно в ту секунду, когда увидел результат.
                'review' => $this->review->of($attempt, $reader),
            ],
        ], HttpResponse::HTTP_CREATED);
    }

    /* ---------- То же, но при версии документа (2026-09-12) ---------- */

    /*
     * Версия — часть документа, а не отдельный материал: права спрашиваются у
     * документа, а меняется лишь то, при чём стоит тест. Поэтому здесь короткие
     * двойники, а не второй контроллер: устройство теста от владельца не
     * зависит — оценка, разбор и статистика у них одни и те же.
     */

    public function saveForVersion(
        SaveQuizRequest $request,
        Regulation $regulation,
        RegulationVersion $version,
        SaveQuiz $saveQuiz,
    ): QuizResource {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $version);

        /** @var array{title: string, description?: ?string, max_attempts?: ?int, questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($saveQuiz->handle($version, $attributes));
    }

    public function destroyForVersion(Regulation $regulation, RegulationVersion $version): Response
    {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $version);

        $version->quiz()->delete();

        return response()->noContent();
    }

    public function statisticsForVersion(
        Regulation $regulation,
        RegulationVersion $version,
        QuizStatistics $statistics,
    ): JsonResponse {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $version);

        $quiz = $version->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        return response()->json(['data' => $statistics->of($quiz)]);
    }

    public function attemptForVersion(
        Regulation $regulation,
        RegulationVersion $version,
        QuizAttempt $attempt,
    ): QuizAttemptResource {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $version);

        $quiz = $version->quiz;

        abort_if(
            $quiz === null || $attempt->quiz_id !== $quiz->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $attempt->setAttribute('review', $this->review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Пройти проверку при версии. Сдал — документ считается прочитанным, а
     * версия остаётся пометкой на отметке, см. GradeQuizAttempt.
     *
     * @throws ConflictException
     */
    public function submitForVersion(
        SubmitQuizRequest $request,
        Regulation $regulation,
        RegulationVersion $version,
        GradeQuizAttempt $grade,
        MaterialVersions $versions,
    ): JsonResponse {
        Gate::authorize('acknowledge', $regulation);
        $this->ensureBelongs($regulation, $version);

        /** @var User $reader */
        $reader = $request->user();

        // Закрытая чужая версия отвечает «не найдено» и здесь: сдать проверку
        // по тексту, которого тебе не показывали, нельзя.
        abort_unless($versions->allows($version, $reader), HttpResponse::HTTP_NOT_FOUND);

        $quiz = $version->quiz;

        abort_if($quiz === null, HttpResponse::HTTP_NOT_FOUND);

        $attempt = $grade->handle($quiz, $reader, $request->answers(), null);

        return response()->json([
            'data' => [
                'id' => $attempt->getKey(),
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'is_acknowledged' => $attempt->passed,
                'review' => $this->review->of($attempt, $reader),
            ],
        ], HttpResponse::HTTP_CREATED);
    }

    /**
     * Версия чужого документа — тот же случай, что и её отсутствие.
     */
    private function ensureBelongs(Regulation $regulation, RegulationVersion $version): void
    {
        abort_if($version->regulation_id !== $regulation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
