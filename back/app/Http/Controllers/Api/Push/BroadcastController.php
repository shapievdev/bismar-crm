<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Push;

use App\Actions\Push\SendBroadcast;
use App\Http\Controllers\Controller;
use App\Http\Requests\Push\SendBroadcastRequest;
use App\Http\Resources\Push\BroadcastResource;
use App\Models\PushBroadcast;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Рассылки уведомлений.
 *
 * Отправляет администратор — это громкое действие: телефон звонит у всей
 * компании, и права, отмеченного галочкой, для такого мало (EnsureAdministrator
 * на маршрутах).
 */
final class BroadcastController extends Controller
{
    /** Сколько отправок помнит экран: дальше это уже архив, а не история. */
    private const HISTORY = 20;

    public function index(): AnonymousResourceCollection
    {
        $broadcasts = PushBroadcast::query()
            ->with('author', 'department', 'group')
            ->latest('sent_at')
            ->limit(self::HISTORY)
            ->get();

        return BroadcastResource::collection($broadcasts);
    }

    public function store(SendBroadcastRequest $request, SendBroadcast $send): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        $broadcast = $send->handle($request->toAttributes(), $author);

        return BroadcastResource::make($broadcast)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }
}
