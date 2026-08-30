<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\PersonResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Кому можно написать.
 *
 * Все сотрудники: мессенджер для того и заводится, чтобы человек нашёл коллегу,
 * которого не знает по имени, — например ответственного за чужой курс.
 */
final class ContactController extends Controller
{
    private const LIMIT = 30;

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->whereKeyNot($request->user()?->getKey() ?? 0)
            // Уволенных в списке нет: на платформу они больше не заходят,
            // и написанное им осталось бы непрочитанным.
            ->employed()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::LIMIT)
            ->get();

        return PersonResource::collection($people);
    }
}
