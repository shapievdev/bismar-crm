<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use Anthropic\Core\Exceptions\APIException;
use App\Actions\Ai\SuggestLessonAnswers;
use App\Actions\Lms\SaveLessonAnswers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveLessonAnswersRequest;
use App\Http\Resources\Lms\LessonAnswerResource;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Таблица урока: какие вопросы он разбирает и где ответ на каждый.
 */
final class LessonAnswerController extends Controller
{
    /**
     * Записывает таблицу целиком. Редактор всегда присылает её всю, поэтому
     * один маршрут вместо пары «создать / изменить» — как и у теста урока.
     */
    public function save(
        SaveLessonAnswersRequest $request,
        Lesson $lesson,
        SaveLessonAnswers $save,
    ): AnonymousResourceCollection {
        /** @var list<array<string, mixed>> $rows */
        $rows = $request->validated('answers');

        return LessonAnswerResource::collection($save->handle($lesson, $rows));
    }

    /**
     * Черновик от модели: вопросы, которые она вычитала в тексте урока.
     *
     * Ничего не сохраняет — автор отбирает нужные и присылает их обычным
     * сохранением. Отказ модели здесь не беда: разметить таблицу руками можно
     * и без подсказки, поэтому ошибка сообщается, но ничему не мешает.
     */
    public function suggest(Request $request, Lesson $lesson, SuggestLessonAnswers $suggest): JsonResponse
    {
        // Расшифровка, если просят вопросы у неё одной. Чужую сюда не передать:
        // выбирается она среди расшифровок этого урока.
        $transcript = $lesson->transcripts()
            ->whereKey($request->integer('transcript'))
            ->value('id');

        try {
            return response()->json(['data' => $suggest->handle($lesson, $transcript)]);
        } catch (APIException $exception) {
            Log::error('Черновик таблицы урока не составлен.', ['exception' => $exception]);

            return response()->json(
                ['message' => 'Подсказка сейчас недоступна. Заполните таблицу вручную или попробуйте позже.'],
                HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
