<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Уволенный не пользуется платформой.
 *
 * Увольнение обрывает открытые сессии, так что сюда уволенный обычно и не
 * доходит. Но сессия — не единственный ключ от двери, и полагаться на её
 * уборку значило бы держать вход открытым ровно настолько, насколько повезёт.
 * Проверка стоит на входе, рядом с `auth:sanctum`: где спрашивают, кто это,
 * там же спрашивают, работает ли он ещё.
 *
 * Отказ — 401, а не 403: для приложения уволенный именно что не вошёл, и SPA
 * на 401 уже умеет — уводит на форму входа, где сказано, в чём дело.
 */
final class EnsureEmployed
{
    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Гостя не касается: его дело решает `auth:sanctum`, идущий первым.
        if ($user instanceof User && $user->isDismissed()) {
            throw new AuthenticationException('Доступ к платформе закрыт.');
        }

        return $next($request);
    }
}
