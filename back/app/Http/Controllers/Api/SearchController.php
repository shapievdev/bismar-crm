<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Search\Everywhere;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Поиск из шапки — по всей платформе разом.
 *
 * Права на маршруте нет намеренно: искать вправе всякий, кто вошёл, а что ему
 * покажут — решает каждый раздел за себя (см. Everywhere). Право на маршруте
 * означало бы, что человеку без права на курсы поиск недоступен вовсе, — хотя
 * коллегу и новость он найти вправе.
 */
final class SearchController extends Controller
{
    public function index(Request $request, Everywhere $everywhere): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'data' => $everywhere->find($request->query('q'), $reader),
        ]);
    }
}
