<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Chat\MaterialCard;
use App\Support\Lms\MaterialReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Карточка материала, с которого собираются написать.
 *
 * Мессенджер показывает её над полем ввода — «пишете вот об этом», — а к
 * отправленной реплике ту же карточку прикладывает сервер (см.
 * MessageController::store). Название и адрес экран не придумывает и не несёт
 * в адресе: он спрашивает их здесь, и в поле ввода человек видит ровно то, что
 * увидит адресат.
 */
final class MaterialCardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(MaterialReference::KINDS)],
            'id' => ['required', 'integer'],
        ]);

        /** @var User $reader */
        $reader = $request->user();

        $material = MaterialReference::find((string) $validated['kind'], (int) $validated['id']);

        // Чужого закрытого материала для спрашивающего не существует — как и
        // выброшенного: ответ один и тот же, и по нему нельзя узнать, какой
        // случай перед ним.
        if ($material === null || ! $material->visibleTo($reader)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => MaterialCard::render($material->card())]);
    }
}
