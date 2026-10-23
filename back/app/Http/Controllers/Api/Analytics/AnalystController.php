<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Actions\Analytics\AnswerWithDashboard;
use App\Data\Analytics\DashboardSpec;
use App\Enums\AnalyticsAnswerOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalystRequest;
use App\Models\AnalyticsQuestion;
use App\Models\User;
use App\Support\Analytics\AnalyticsUnavailable;
use App\Support\Analytics\DashboardExecutor;
use App\Support\Analytics\DashboardVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * ИИ-аналитик: спросить о цифрах словами.
 *
 * Три адреса — спросить, прочитать свою переписку, очистить её. Переписка у
 * каждого своя, и это не настройка приватности, а свойство разговора: вопросы
 * одного не должны достраивать вопросы другого.
 *
 * Право на маршруте — `analytics.view`, то же, что у вкладок. Разделять «кто
 * видит выручку» и «кто складывает из неё панель» нечем: это та же цифра, в
 * том же разрезе, посчитанная тем же кодом.
 */
final class AnalystController extends Controller
{
    /**
     * Спросить.
     *
     * Один вызов модели, и ответ приходит сразу с посчитанными цифрами: собрать
     * описание и не посчитать его значило бы показать сотруднику сетку, которая
     * наполнится неизвестно когда.
     */
    public function ask(AnalystRequest $request, AnswerWithDashboard $analyst): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json(['data' => $analyst->handle($reader, $request->question())]);
    }

    /**
     * Своя переписка — с пересчитанными панелями.
     *
     * Дашборды хранятся описанием, а не цифрами, и считаются заново при каждом
     * чтении: открыв вчерашний разговор, сотрудник должен увидеть, как дела
     * сейчас. Это и дешевле снимка, и честнее его.
     *
     * Недоступный сервер аналитики переписку не отменяет: слова остаются,
     * панели просто не приезжают — ClickHouse стоит у компании и доезжает
     * туннелем, «не ответил» здесь обыкновенное дело.
     */
    public function history(
        Request $request,
        DashboardVocabulary $vocabulary,
        DashboardExecutor $executor,
    ): JsonResponse {
        /** @var User $reader */
        $reader = $request->user();

        $turns = AnalyticsQuestion::query()
            ->conversationOf($reader)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'question', 'answer', 'dashboard', 'outcome', 'created_at']);

        return response()->json([
            'data' => $turns
                ->map(fn (AnalyticsQuestion $turn): array => [
                    'id' => $turn->getKey(),
                    'question' => (string) $turn->question,
                    'answer' => (string) $turn->answer,
                    'composed' => $turn->outcome === AnalyticsAnswerOutcome::Composed,
                    'dashboard' => $this->recount($turn, $vocabulary, $executor),
                    'asked_at' => $turn->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Очистить переписку.
     *
     * Записи остаются в журнале — он ведётся ради вопросов, а не ради людей, —
     * но ни на экране, ни в памяти модели их больше нет: очистив переписку,
     * сотрудник ждёт разговора с чистого листа.
     */
    public function forget(Request $request): Response
    {
        AnalyticsQuestion::query()
            ->where('user_id', $request->user()?->getKey())
            ->whereNull('hidden_at')
            ->update(['hidden_at' => now()]);

        return response()->noContent();
    }

    /**
     * Пересчитать сохранённое описание.
     *
     * @return array<string, mixed>|null
     */
    private function recount(
        AnalyticsQuestion $turn,
        DashboardVocabulary $vocabulary,
        DashboardExecutor $executor,
    ): ?array {
        if (! is_array($turn->dashboard)) {
            return null;
        }

        $spec = DashboardSpec::fromModel($turn->dashboard, $vocabulary);

        if ($spec === null) {
            return null;
        }

        try {
            return $executor->render($spec);
        } catch (AnalyticsUnavailable) {
            return null;
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }
    }
}
