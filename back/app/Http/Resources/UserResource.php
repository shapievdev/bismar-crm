<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The joined form for anywhere a name is shown, the parts for the
            // profile form that edits them.
            'name' => $this->name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,

            'email' => $this->email,
            'avatar_url' => $this->avatarUrl(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Superadmin, administrator or plain user.
            'level' => $this->accessLevel()->value,
            'level_label' => $this->accessLevel()->label(),

            // What was ticked for this person. Empty for an administrator, who
            // carries everything by standing rather than by grant.
            'own_permissions' => $this->permissions->pluck('name')->sort()->values()->all(),

            // Everything they can actually do. The SPA uses it to hide what
            // they cannot reach — a convenience only, since the API re-checks
            // every request.
            'permissions' => $this->effectivePermissions(),
        ];
    }

    /**
     * @return list<string>
     */
    private function effectivePermissions(): array
    {
        // An administrator passes every check through Gate::before, so listing
        // their grants would understate what they can do.
        if ($this->accessLevel()->grantsEverything()) {
            return Permission::values();
        }

        return $this->permissions->pluck('name')->sort()->values()->all();
    }
}
