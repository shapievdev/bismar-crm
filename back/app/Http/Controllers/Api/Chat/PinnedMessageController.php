<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\PinMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Закреплённое наверху переписки.
 */
final class PinnedMessageController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        Message $message,
        PinMessage $pin,
    ): MessageResource {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('pin', $message);

        /** @var User $actor */
        $actor = $request->user();

        return MessageResource::make(
            $pin->pin($message, $actor)->load(['author', 'attachments', 'replyTo.author', 'reactions.person']),
        );
    }

    public function destroy(Conversation $conversation, Message $message, PinMessage $pin): MessageResource
    {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('pin', $message);

        return MessageResource::make(
            $pin->unpin($message)->load(['author', 'attachments', 'replyTo.author', 'reactions.person']),
        );
    }

    /**
     * Реплика из адреса должна лежать в переписке из адреса — иначе чужую можно
     * было бы закрепить, подставив к своему разговору номер из соседнего.
     */
    private function ensureBelongs(Message $message, Conversation $conversation): void
    {
        abort_unless($message->conversation_id === $conversation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
