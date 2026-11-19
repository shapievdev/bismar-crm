<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Уволенному закрыты все рабочие разделы. Единственное исключение —
 * восстановление своей сессии для выходной анкеты и выход из неё.
 * Новые увольнения с незаполненной анкетой получают отдельный код,
 * чтобы интерфейс мог перенаправить человека на форму.
 */
final class EnsureEmployed
{
    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next, string $allowExitSurvey = 'no'): Response
    {
        $user = $request->user();

        // Гостя не касается: его дело решает `auth:sanctum`, идущий первым.
        if ($user instanceof User && $user->isDismissed()) {
            if ($user->pendingExitSurvey() !== null) {
                if ($allowExitSurvey === 'yes') {
                    return $next($request);
                }

                return response()->json(['message' => 'Доступна только анкета при увольнении.', 'code' => 'EXIT_SURVEY_REQUIRED'], 403);
            }
            throw new AuthenticationException('Доступ к платформе закрыт.');
        }

        return $next($request);
    }
}
