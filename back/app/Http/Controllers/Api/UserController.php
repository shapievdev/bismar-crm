<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\User\SyncUserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles.permissions', 'permissions')
            ->orderBy('name')
            ->paginate(25);

        return UserResource::collection($users);
    }

    public function updateRoles(
        UpdateUserRolesRequest $request,
        User $user,
        SyncUserRoles $syncUserRoles,
    ): UserResource {
        /** @var User $actor */
        $actor = $request->user();

        return UserResource::make(
            $syncUserRoles->handle($user, $request->roles(), $actor),
        );
    }
}
