<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Подписки на уведомления: устройство говорит, куда ему слать.
 *
 * Права здесь ни при чём — человек распоряжается своим телефоном, — но чужую
 * подписку не тронуть: и запись, и удаление идут по вошедшему.
 */
final class PushSubscriptionController extends Controller
{
    /**
     * Заводит подписку — или переписывает её на нынешнего человека.
     *
     * Адрес выдаёт браузер, и он же ключ: на одном компьютере сменились двое —
     * подписка достаётся тому, кто вошёл сейчас, а не остаётся у прежнего.
     */
    public function store(StorePushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $request->validated('endpoint')],
            [
                'user_id' => $user->getKey(),
                'public_key' => $request->validated('public_key'),
                'auth_token' => $request->validated('auth_token'),
                'device' => $request->validated('device'),
            ],
        );

        return response()->noContent();
    }

    /**
     * Отписка. Своего адреса человек не знает наизусть — его присылает
     * браузер, — поэтому удаляем по адресу и только у себя.
     */
    public function destroy(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = (string) $request->input('endpoint');

        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->when($endpoint !== '', fn ($query) => $query->where('endpoint', $endpoint))
            ->delete();

        return response()->noContent();
    }

    /**
     * Настроены ли уведомления на сервере и подписано ли это устройство.
     *
     * Публичный ключ отдаётся отсюда, а не переменной сборки: он привязан к
     * серверу, а не к сборке фронта, и на другом стенде он другой.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = (string) $request->query('endpoint');

        return response()->json([
            'data' => [
                'configured' => is_string(config('push.vapid.public_key'))
                    && config('push.vapid.public_key') !== '',
                'public_key' => config('push.vapid.public_key'),
                'subscribed' => $endpoint !== '' && PushSubscription::query()
                    ->where('user_id', $user->getKey())
                    ->where('endpoint', $endpoint)
                    ->exists(),
            ],
        ]);
    }
}
