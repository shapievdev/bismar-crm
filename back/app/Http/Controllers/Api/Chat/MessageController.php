<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\DeleteMessage;
use App\Actions\Chat\EditMessage;
use App\Actions\Chat\SayInConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\EditMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
            ->with(['author', 'attachments', 'replyTo.author'])
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

        $message = $say->handle(
            $conversation,
            $author,
            $request->body(),
            $request->attachments(),
            $request->replyToId(),
        );

        return MessageResource::make($message->load(['author', 'attachments', 'replyTo.author']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Правка своей реплики.
     *
     * Принадлежность сообщения переписке из адреса проверяется отдельно: без
     * этого чужую реплику можно было бы править, подставив к своей переписке
     * идентификатор из соседней.
     */
    public function update(
        EditMessageRequest $request,
        Conversation $conversation,
        Message $message,
        EditMessage $edit,
    ): MessageResource {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('update', $message);

        /** @var User $editor */
        $editor = $request->user();

        return MessageResource::make($edit->handle($message, $editor, $request->body()));
    }

    public function destroy(
        Conversation $conversation,
        Message $message,
        DeleteMessage $delete,
    ): Response {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('delete', $message);

        $delete->handle($message);

        return response()->noContent();
    }

    private function ensureBelongs(Message $message, Conversation $conversation): void
    {
        abort_unless($message->conversation_id === $conversation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
