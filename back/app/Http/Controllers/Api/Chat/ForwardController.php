<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\ForwardMessages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ForwardMessagesRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Пересылка сказанного в другой разговор.
 *
 * Права спрашиваются дважды и о разном: читать ту переписку, откуда берут, и
 * писать в ту, куда кладут. Одной проверки мало ни в одну сторону — без первой
 * чужой разговор вычитывается по номерам, без второй в чужой разговор пишут.
 */
final class ForwardController extends Controller
{
    public function store(
        ForwardMessagesRequest $request,
        Conversation $conversation,
        ForwardMessages $forward,
    ): JsonResponse {
        Gate::authorize('view', $conversation);

        $target = Conversation::query()->findOrFail($request->targetId());

        Gate::authorize('speak', $target);

        /** @var User $actor */
        $actor = $request->user();

        $ids = $request->messageIds();

        // Порядок — тот, в каком это было сказано, а не тот, в каком нажимали:
        // пересланная переписка должна читаться так же, как читалась исходная.
        $messages = Message::query()
            ->with(['author', 'attachments'])
            ->whereKey($ids)
            ->orderBy('id')
            ->get();

        $forwarded = $forward->handle($messages, $target, $actor);

        return MessageResource::collection($forwarded)
            ->additional(['meta' => ['conversation_id' => $target->getKey()]])
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }
}
