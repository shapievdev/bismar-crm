<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AuthenticatedSessionController extends Controller
{
    /**
     * Authenticate the user and issue a session cookie.
     */
    public function store(LoginRequest $request, AttemptLogin $attemptLogin): UserResource
    {
        $attemptLogin->handle($request->toData(), (string) $request->ip());

        // Prevents session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        return UserResource::make($request->user());
    }

    /**
     * Log the user out and invalidate their session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
