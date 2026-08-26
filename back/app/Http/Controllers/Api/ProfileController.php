<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\User\ChangePassword;
use App\Actions\User\StoreAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreAvatarRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A user's own account. No permission gates it: everyone owns their profile,
 * and every action here reads the signed-in user rather than an id from the
 * URL, so one account can never edit another.
 */
final class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'last_name' => $request->validated('last_name'),
            'first_name' => $request->validated('first_name'),
            // Absent means cleared: the form always sends the whole record.
            'middle_name' => $request->validated('middle_name'),
            'email' => $request->validated('email'),
        ]);

        return UserResource::make($user->refresh());
    }

    /**
     * Nothing about the account changes on screen, so the answer carries no
     * body — the caller already knows what it sent.
     */
    public function updatePassword(UpdatePasswordRequest $request, ChangePassword $changePassword): Response
    {
        /** @var User $user */
        $user = $request->user();

        $changePassword->handle($user, (string) $request->validated('password'));

        return response()->noContent();
    }

    public function storeAvatar(StoreAvatarRequest $request, StoreAvatar $storeAvatar): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($storeAvatar->handle($user, $request->file('avatar')));
    }

    public function destroyAvatar(Request $request, StoreAvatar $storeAvatar): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($storeAvatar->remove($user));
    }
}
