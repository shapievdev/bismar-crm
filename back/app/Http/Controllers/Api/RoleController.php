<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Role\DeleteRole;
use App\Actions\Role\SaveRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\Authorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request, SaveRolePermissions $saveRolePermissions): JsonResponse
    {
        $role = $saveRolePermissions->create(
            name: (string) $request->validated('name'),
            permissions: $request->permissions(),
            guardName: Authorization::GUARD,
        );

        return RoleResource::make($role)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        UpdateRoleRequest $request,
        Role $role,
        SaveRolePermissions $saveRolePermissions,
    ): RoleResource {
        return RoleResource::make(
            $saveRolePermissions->sync($role, $request->permissions()),
        );
    }

    public function destroy(Role $role, DeleteRole $deleteRole): Response
    {
        $deleteRole->handle($role);

        return response()->noContent();
    }
}
