<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\ReactToMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ReactToMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Chat\ReactionSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Отклики под репликой.
 *
 * Одна точка на все три движения — поставить, сменить, снять: для человека это
 * одно нажатие по знаку, и разделять его на «создать» и «удалить» значило бы
 * заставить экран помнить, что там стоит сейчас, и угадывать между двумя
 * быстрыми нажатиями.
 */
final class ReactionController extends Controller
{
    public function store(
        ReactToMessageRequest $request,
        Conversation $conversation,
        Message $message,
        ReactToMessage $react,
    ): JsonResponse {
        abort_unless($message->conversation_id === $conversation->getKey(), HttpResponse::HTTP_NOT_FOUND);

        Gate::authorize('react', $message);

        /** @var User $person */
        $person = $request->user();

        $standing = $react->handle($message, $person, $request->emoji());

        return response()->json(['data' => [
            'message_id' => $message->getKey(),
            'mine' => $standing,
            'reactions' => ReactionSummary::of($message->load('reactions.person')),
        ]]);
    }
}
