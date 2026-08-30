<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Structure;

use App\Actions\Structure\DeleteDepartment;
use App\Actions\Structure\MoveDepartment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\MoveDepartmentRequest;
use App\Http\Requests\Structure\RenameDepartmentRequest;
use App\Http\Requests\Structure\StoreDepartmentRequest;
use App\Http\Resources\Structure\DepartmentResource;
use App\Models\Department;
use App\Support\Structure\CompanyTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Структура компании — дерево отделов.
 *
 * Читает её всякий, кто вошёл: узнать, кто чем занимается и к кому идти с
 * вопросом, — не привилегия (решение пользователя 2026-08-30). Рисует её
 * администратор — за это отвечает EnsureAdministrator на маршрутах.
 */
final class DepartmentController extends Controller
{
    public function __construct(private readonly CompanyTree $tree) {}

    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection($this->tree->build());
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $parentId = (int) $request->validated('parent_id');

        $department = Department::create([
            'name' => $request->validated('name'),
            'parent_id' => $parentId,
            // В конец к соседям: новый отдел встаёт последним, а место среди
            // прочих ему выбирают перетаскиванием.
            'position' => Department::query()->where('parent_id', $parentId)->count(),
        ]);

        return DepartmentResource::make($this->tree->node($department))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Переименование — единственное, что правят у отдела помимо места: всё
     * остальное на карточке складывается из людей и детей.
     */
    public function update(RenameDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update(['name' => $request->validated('name')]);

        return DepartmentResource::make($this->tree->node($department->refresh()));
    }

    /**
     * Перетаскивание карточки: новый родитель и место среди его детей.
     */
    public function move(
        MoveDepartmentRequest $request,
        Department $department,
        MoveDepartment $move,
    ): AnonymousResourceCollection {
        $move->handle(
            $department,
            (int) $request->validated('parent_id'),
            (int) $request->validated('position'),
        );

        // Ответ — всё дерево: перенос переставляет номера у соседей и меняет
        // счётчики у обоих родителей, и собирать это на клиенте по кусочкам
        // значит однажды разойтись с тем, что в базе.
        return DepartmentResource::collection($this->tree->build());
    }

    public function destroy(Department $department, DeleteDepartment $delete): Response
    {
        $delete->handle($department);

        return response()->noContent();
    }
}
