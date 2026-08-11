<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PermissionController extends Controller
{
    /**
     * The catalogue of grantable permissions, for the role editor.
     *
     * It is served from the enum rather than the database so the UI always
     * reflects what the code actually checks.
     */
    public function index(): JsonResponse
    {
        $permissions = array_map(
            static fn (Permission $permission): array => [
                'name' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group(),
                'group_label' => $permission->groupLabel(),
            ],
            Permission::cases(),
        );

        return response()->json(['data' => $permissions]);
    }
}
