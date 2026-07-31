<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $builtIn = RoleEnum::tryFrom($this->name);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $builtIn?->label() ?? $this->name,
            // Built-in roles may be edited but never deleted: code depends on them.
            'is_built_in' => $builtIn !== null,
            'permissions' => $this->permissions->pluck('name')->sort()->values()->all(),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
