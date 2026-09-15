<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\MarkConversation;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Личные отметки на разговоре: приглушить, поднять наверх.
 *
 * Права те же, что на чтение: отметки ничего не меняют для собеседника и видны
 * только тому, кто их поставил. Отдельной политики им поэтому не нужно — нужна
 * лишь уверенность, что человек в этой переписке состоит.
 */
final class ConversationMarkController extends Controller
{
    public function __construct(private readonly MarkConversation $mark) {}

    public function mute(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->apply($request, $conversation, 'muted');
    }

    public function pin(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->apply($request, $conversation, 'pinned');
    }

    /**
     * Обе отметки ставятся и снимаются одной точкой: «приглушить» и «вернуть
     * звук» — это одно решение с двумя исходами, и разводить их по глаголам
     * значило бы заставить экран помнить, что стоит сейчас.
     */
    private function apply(Request $request, Conversation $conversation, string $what): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $person */
        $person = $request->user();

        $on = (bool) $request->validate([$what => ['required', 'boolean']])[$what];

        $membership = $what === 'muted'
            ? $this->mark->mute($conversation, $person, $on)
            : $this->mark->pin($conversation, $person, $on);

        return response()->json(['data' => [
            'conversation_id' => $conversation->getKey(),
            'is_muted' => $membership->muted_at !== null,
            'is_pinned' => $membership->pinned_at !== null,
        ]]);
    }
}
