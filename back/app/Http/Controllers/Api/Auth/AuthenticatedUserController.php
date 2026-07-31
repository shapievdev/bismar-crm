<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class AuthenticatedUserController extends Controller
{
    /**
     * Return the currently authenticated user. The SPA calls this to restore
     * its auth state after a page reload.
     */
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
