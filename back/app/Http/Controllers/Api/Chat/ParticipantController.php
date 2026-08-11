<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\ManageParticipants;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\PersonResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Состав группы.
 */
final class ParticipantController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        ManageParticipants $participants,
    ): AnonymousResourceCollection {
        Gate::authorize('manage', $conversation);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        $participants->add($conversation, $validated['user_ids'], $actor);

        return PersonResource::collection($conversation->activeParticipants()->get());
    }

    public function destroy(
        Request $request,
        Conversation $conversation,
        User $user,
        ManageParticipants $participants,
    ): AnonymousResourceCollection {
        Gate::authorize('manage', $conversation);

        /** @var User $actor */
        $actor = $request->user();

        // Владелец не убирает себя этим путём: для этого есть выход из группы,
        // и он отмечается в ленте другими словами.
        abort_if($user->is($actor), 422, 'Себя из группы убирают выходом.');

        $participants->remove($conversation, $user, $actor);

        return PersonResource::collection($conversation->activeParticipants()->get());
    }
}
