<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\SayInConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Лента переписки.
 */
final class MessageController extends Controller
{
    /** Сколько сообщений отдаётся за раз. */
    private const PAGE = 40;

    /**
     * Кусок ленты от конца, а не страница с номером.
     *
     * Переписка растёт снизу, и «страница 3» в ней означает разное до и после
     * нового сообщения. «Сорок штук до вот этого» — не означает.
     */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $before = $request->integer('before');

        $messages = $conversation->messages()
            ->with(['author', 'attachments'])
            ->when($before > 0, fn ($query) => $query->where('id', '<', $before))
            ->latest('id')
            ->limit(self::PAGE)
            ->get()
            // Выбираем с конца, показываем с начала: читают разговор сверху вниз.
            ->reverse()
            ->values();

        return MessageResource::collection($messages);
    }

    public function store(
        SendMessageRequest $request,
        Conversation $conversation,
        SayInConversation $say,
    ): JsonResponse {
        Gate::authorize('speak', $conversation);

        /** @var User $author */
        $author = $request->user();

        $message = $say->handle($conversation, $author, $request->body(), $request->attachments());

        return MessageResource::make($message->load(['author', 'attachments']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }
}
