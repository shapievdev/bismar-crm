<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Group\SaveGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Группы сотрудников — списки людей, собранные вручную.
 *
 * Читает их всякий, кто вошёл, — как и структуру: название группы не тайна, а
 * без него не выбрать адресата новости тому, кто её ведёт. Меняет состав
 * администратор (EnsureAdministrator на маршрутах): группа — это адресат
 * рассылки на всю компанию, и права, отмеченного галочкой, для такого мало.
 */
final class GroupController extends Controller
{
    /**
     * Список групп — с числом людей и поиском по названию.
     *
     * Состав не разворачивается: тридцать групп прислали бы половину штата
     * ради одной строки. За составом ходят в карточку.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = Group::query()
            ->matching($request->query('search'))
            // Уволенные не в счёт, как и в самой связи: число говорит, скольких
            // эта группа позовёт сегодня.
            ->withCount('people')
            ->ordered()
            ->get();

        return GroupResource::collection($groups);
    }

    /**
     * Карточка группы — с составом.
     */
    public function show(Group $group): GroupResource
    {
        return GroupResource::make($this->withPeople($group));
    }

    public function store(SaveGroupRequest $request): JsonResponse
    {
        $group = Group::create($request->toAttributes());

        return GroupResource::make($this->withPeople($group))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Название и описание. Состав правят отдельно — это разные решения и
     * приходят они с разных концов интерфейса.
     */
    public function update(SaveGroupRequest $request, Group $group): GroupResource
    {
        $group->update($request->toAttributes());

        return GroupResource::make($this->withPeople($group->refresh()));
    }

    /**
     * Удаление насовсем.
     *
     * Строки состава уходят вместе с группой, а рассылки в истории остаются:
     * у них `group_id` обнуляется, и запись говорит «группе», не называя её, —
     * лучше, чем стёртая история.
     */
    public function destroy(Group $group): Response
    {
        $group->delete();

        return response()->noContent();
    }

    /** Состав и его число: то, из чего состоит карточка группы. */
    private function withPeople(Group $group): Group
    {
        return $group->load('people')->loadCount('people');
    }
}
