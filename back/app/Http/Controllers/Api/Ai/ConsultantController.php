<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ai;

use Anthropic\Core\Exceptions\APIException;
use App\Actions\Ai\AnswerFromKnowledgeBase;
use App\Actions\Ai\RecordQuestion;
use App\Enums\AnswerFeedback;
use App\Enums\ConsultantOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AskRequest;
use App\Http\Requests\Ai\FeedbackRequest;
use App\Http\Requests\Ai\FollowUpRequest;
use App\Http\Resources\Ai\AnswerResource;
use App\Http\Resources\Ai\ExchangeResource;
use App\Models\ConsultantQuestion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Questions about the knowledge base, answered from the knowledge base.
 *
 * Gated by the same right as reading the material: the consultant quotes only
 * published courses, so anyone who may open them may ask about them.
 */
final class ConsultantController extends Controller
{
    /** Сколько последних разговоров возвращать. */
    private const HISTORY = 50;

    /**
     * Переписка сотрудника с консультантом — только его собственная.
     *
     * Читается из журнала: он и так хранит каждый заданный вопрос с ответом, и
     * заводить вторую запись того же самого значило бы держать две правды об
     * одном разговоре.
     *
     * Сбои модели пропускаются. В журнале на их месте лежит сообщение
     * поставщика — оно для того, кто чинит, а не для того, кто спрашивал: ему
     * про недоступность консультанта сказали ещё тогда.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $exchanges = ConsultantQuestion::query()
            ->where('user_id', $request->user()?->getKey())
            ->where('outcome', '!=', ConsultantOutcome::Failed->value)
            ->whereNull('hidden_at')
            // Урок дополнения вместе с курсом: ссылка ведёт в курс, а не в
            // урок сам по себе.
            ->with('resolutionLesson.module.course:id,title,slug')
            // По ключу вслед за временем: два вопроса, заданных в одну секунду,
            // время не различает, и порядок их показа остаётся на усмотрение
            // базы — переписка при этом читается вразнобой.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->get()
            // Свежие выбираем, старые показываем: разговор читается сверху вниз.
            ->reverse()
            ->values();

        $this->markResolutionsSeen($exchanges);

        return ExchangeResource::collection($exchanges);
    }

    /**
     * Дополнения, дошедшие до глаз сотрудника, перестают быть новыми.
     *
     * Запись при чтении — сознательно: «прочитано» и означает, что переписку
     * открыли. Отметка ставится после сборки ответа, поэтому в той выдаче, где
     * дополнение показано впервые, оно ещё помечено новым, а в следующей уже нет.
     *
     * @param  Collection<int, ConsultantQuestion>  $exchanges
     */
    private function markResolutionsSeen(Collection $exchanges): void
    {
        $unseen = $exchanges
            ->filter(static fn (ConsultantQuestion $one): bool => $one->isResolved()
                && $one->resolution_seen_at === null)
            ->modelKeys();

        if ($unseen === []) {
            return;
        }

        ConsultantQuestion::query()->whereKey($unseen)->update(['resolution_seen_at' => now()]);
    }

    /**
     * Помог ли ответ — единственный сигнал, которого журналу взять неоткуда.
     *
     * Он умеет отличить «модель промолчала» от «модель не сослалась ни на что»,
     * но «ответила не о том» выглядит для него удачей: ссылки на месте,
     * материал найден. Знает об этом только тот, кто спрашивал.
     *
     * Оценку можно переменить: человек нажимает «не помог», перечитывает ответ
     * и находит там то, что искал, — и наоборот.
     */
    public function feedback(FeedbackRequest $request, ConsultantQuestion $question): ExchangeResource
    {
        $this->authoriseOwnership($request, $question);

        $question->forceFill([
            'feedback' => $request->feedback(),
            'feedback_at' => now(),
        ])->save();

        return ExchangeResource::make($question);
    }

    /**
     * Заявка на дополнение ответа.
     *
     * Отдельно от оценки: палец вниз ставят и молча, а заявка — просьба, на
     * которую отвечает живой человек, и в журнале она стоит впереди всех
     * догадок о пробелах.
     *
     * Повторная заявка на тот же вопрос ничего не меняет: она уже подана, и
     * второй раз просить не о чем.
     */
    public function requestFollowUp(FollowUpRequest $request, ConsultantQuestion $question): ExchangeResource
    {
        $this->authoriseOwnership($request, $question);

        $question->forceFill([
            'feedback' => AnswerFeedback::Unhelpful,
            'feedback_at' => $question->feedback_at ?? now(),
            'requested_at' => $question->requested_at ?? now(),
            'request_note' => $request->note() ?? $question->request_note,
        ])->save();

        return ExchangeResource::make($question);
    }

    /**
     * Свой вопрос и ничей больше.
     *
     * Оценка и заявка — это слова о собственном разговоре, и ставить их за
     * другого нельзя даже администратору: в журнале это выглядело бы как мнение
     * спрашивавшего.
     */
    private function authoriseOwnership(Request $request, ConsultantQuestion $question): void
    {
        abort_unless($question->user_id === $request->user()?->getKey(), HttpResponse::HTTP_FORBIDDEN);
    }

    /**
     * Убирает переписку с глаз сотрудника.
     *
     * Отметкой, а не удалением. У записи два читателя с несовместимыми
     * правами: сотрудник вправе очистить свою переписку, а автор курса — нет,
     * для него это перечень того, чего в базе знаний не хватает. Удали строку
     * по просьбе первого, и второй лишится сведений о пробеле, которого он ещё
     * не закрыл. Обратное — удаление автором — как раз удаление: см.
     * QuestionLogController::destroy().
     */
    public function forget(Request $request): Response
    {
        ConsultantQuestion::query()
            ->where('user_id', $request->user()?->getKey())
            ->whereNull('hidden_at')
            ->update(['hidden_at' => now()]);

        return response()->noContent();
    }

    public function ask(
        AskRequest $request,
        AnswerFromKnowledgeBase $answer,
        RecordQuestion $journal,
    ): JsonResponse|AnswerResource {
        /** @var User $reader */
        $reader = $request->user();

        $question = $request->question();
        $startedAt = microtime(true);

        try {
            // Отвечаем по тому, что открыто спрашивающему: приватный курс
            // отвечает своим и молчит для остальных.
            $result = $answer->handle($question, $reader);

            $logged = $journal->answered($question, $result, $reader, microtime(true) - $startedAt);

            return new AnswerResource($result, $logged?->getKey());
        } catch (APIException $exception) {
            $journal->failed($question, $exception, $request->user(), microtime(true) - $startedAt);

            // The model is a dependency that can be down, rate-limited or
            // misconfigured. None of that is the reader's problem to decode,
            // and none of it should surface a provider error message.
            Log::error('Консультант не смог ответить.', ['exception' => $exception]);

            return response()->json(
                ['message' => 'Консультант сейчас недоступен. Попробуйте позже.'],
                HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
