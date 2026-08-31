<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Group\StoreGroupMemberRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\User;

/**
 * Состав группы: кого в неё внесли.
 *
 * Ответ на любую правку — карточка группы целиком: состав меняется вместе с
 * числом людей, и собирать их на клиенте по кусочкам значит однажды разойтись
 * с тем, что в базе.
 */
final class GroupMemberController extends Controller
{
    /**
     * Добавляет людей в группу.
     *
     * Никого лишнего не убирает: экран посылает тех, кого назвали сейчас, а не
     * весь состав, и полная замена стёрла бы остальных. Уже состоящий приходит
     * второй раз без последствий — роли внутри группы нет, менять нечего.
     */
    public function store(StoreGroupMemberRequest $request, Group $group): GroupResource
    {
        $group->members()->syncWithoutDetaching($request->userIds());

        return GroupResource::make($this->withPeople($group->refresh()));
    }

    /**
     * Убирает человека из группы.
     *
     * Через `members()`, а не через `people()`: та отсеивает уволенных, и
     * изъятие ушедшего из группы не дошло бы до базы.
     */
    public function destroy(Group $group, User $user): GroupResource
    {
        $group->members()->detach($user->getKey());

        return GroupResource::make($this->withPeople($group->refresh()));
    }

    /** Состав и его число: то, из чего состоит карточка группы. */
    private function withPeople(Group $group): Group
    {
        return $group->load('people')->loadCount('people');
    }
}
