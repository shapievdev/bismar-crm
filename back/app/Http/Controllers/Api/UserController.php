<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\User\CreateUser;
use App\Actions\User\SyncUserAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserAccessRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // By surname, as a staff list is read, falling back to the given name
        // so that a record without a surname still lands in its alphabetical
        // place instead of being swept to the end with the other nulls.
        //
        // Collated to ICU because the databases use the C collation, which
        // orders Cyrillic by byte value and would put "Ёлкин" after "Яковлев".
        $users = User::query()
            ->with('roles', 'permissions')
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->paginate(25);

        return UserResource::collection($users);
    }

    /**
     * A new colleague arrives with nothing granted; access is set afterwards,
     * on their own screen.
     */
    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        return UserResource::make($createUser->handle($request->toData()))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update(array_filter([
            'last_name' => $request->validated('last_name'),
            'first_name' => $request->validated('first_name'),
            'email' => $request->validated('email'),
            // Sent only when the administrator is resetting it; array_filter
            // would also drop a cleared patronymic, so that one is set below.
            'password' => $request->validated('password'),
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        $user->update(['middle_name' => $request->validated('middle_name')]);

        return UserResource::make($user->refresh()->load('roles', 'permissions'));
    }

    /**
     * What this person may do: their standing and their permissions, saved as
     * one decision.
     */
    public function updateAccess(
        UpdateUserAccessRequest $request,
        User $user,
        SyncUserAccess $syncUserAccess,
    ): UserResource {
        /** @var User $actor */
        $actor = $request->user();

        return UserResource::make(
            $syncUserAccess->handle($user, $request->level(), $request->permissions(), $actor),
        );
    }
}
