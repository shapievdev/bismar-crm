<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\WorkAsSomebodyElse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Переход в чужую учётную запись и обратно.
 *
 * Права на маршруте нет намеренно: «кому это можно» — не право с галочкой, а
 * уровень доступа, и спрашивается он в действии вместе с остальными четырьмя
 * правилами (см. WorkAsSomebodyElse). Отказ приходит 409 с объяснением: человек
 * должен понимать, почему кнопка не сработала.
 *
 * Отвечают оба метода тем же, чем вход, — учётной записью, под которой работают
 * теперь. Экран по ней перерисовывается целиком.
 */
final class ImpersonationController extends Controller
{
    public function store(Request $request, User $user, WorkAsSomebodyElse $switch): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return UserResource::make($switch->start($actor, $user, $request));
    }

    public function destroy(Request $request, WorkAsSomebodyElse $switch): UserResource
    {
        return UserResource::make($switch->stop($request));
    }
}
