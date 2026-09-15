<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Теги на одном человеке.
 *
 * Отдельным адресом, а не полем карточки: тег вешают и снимают по ходу
 * разговора — «этого в резерв», «этому продлили испытательный», — и требовать
 * ради одной пометки отправки всей карточки значило бы отправлять вместе с ней
 * и то, что в этот момент никто не собирался менять.
 *
 * Уволенного тегировать можно. Ярлык говорит о человеке, а не о его нынешнем
 * положении, и «ушёл из резерва» — такая же часть кадровой истории, как и всё
 * остальное.
 */
final class UserStaffTagController extends Controller
{
    public function update(Request $request, User $user): UserResource
    {
        $input = $request->validate([
            'tags' => ['array'],
            'tags.*' => ['integer', 'exists:staff_tags,id'],
        ]);

        /** @var list<int> $tags */
        $tags = array_values(array_unique(array_map(intval(...), $input['tags'] ?? [])));

        // Кто повесил — записывается на каждую связь: через полгода «почему он
        // в резерве» спрашивают у того, кто это решил.
        $user->staffTags()->sync(array_fill_keys(
            $tags,
            ['assigned_by_id' => $request->user()?->getKey()],
        ));

        return UserResource::make($user->load('roles', 'permissions', 'departments', 'mentor', 'staffTags'));
    }
}
