<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\Auth\Impersonation;
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
    public function destroy(Request $request, Impersonation $impersonation): Response
    {
        /*
         * Выход из приложения закрывает и окно работы от чужого имени, если оно
         * открыто: незакрытое окно означало бы, что суперадминистратор сидит под
         * сотрудником вечно, — а по этим окнам потом разбирают, кто что сделал.
         *
         * Именно закрывает, а не возвращает к себе: «выйти» означает выйти
         * совсем, и вернуться в свою учётную запись предлагает полоса наверху
         * экрана, а не эта кнопка.
         */
        $impersonation->finish();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
