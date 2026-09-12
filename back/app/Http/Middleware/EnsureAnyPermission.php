<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Хотя бы одно право из перечисленных.
 *
 * Стандартный `can:` спрашивает ровно одно, а у базы знаний появились разделы
 * со своими правами (курсы, документы, справочники — см. App\Enums\Permission),
 * и есть места, общие для всех разделов сразу: консультант отвечает по всему,
 * что человеку открыто, корзина показывает выброшенное из любого раздела.
 * Требовать там право курсов значило бы закрыть консультанта от того, кому
 * открыты одни справочники, — а перечислить разделы в самом маршруте нечем.
 *
 * Отказ — 403 с тем же исключением, что и у `can:`: для приложения это тот же
 * случай, и обработчик у него один.
 */
final class EnsureAnyPermission
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            if ($user?->can($permission)) {
                return $next($request);
            }
        }

        throw new AuthorizationException;
    }

    /**
     * Готовая строка для маршрута: `EnsureAnyPermission::of(...$permissions)`.
     *
     * Собирается здесь, чтобы в маршрутах не склеивали имя класса с двоеточием
     * руками — с перечислением через запятую промахнуться легко, а ошибка
     * такова, что маршрут просто перестанет пускать кого бы то ни было.
     */
    public static function of(Permission ...$permissions): string
    {
        return self::class.':'.implode(',', array_map(
            static fn (Permission $permission): string => $permission->value,
            $permissions,
        ));
    }
}
