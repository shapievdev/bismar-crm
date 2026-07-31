<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Authorization;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Application-level role model.
 *
 * Extending the package model gives the CRM a place to hang its own behaviour
 * and lets roles be addressed by their stable name in URLs (`/api/roles/manager`)
 * instead of an opaque id.
 */
class Role extends SpatieRole
{
    /** @var string */
    protected $guard_name = Authorization::GUARD;

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * The package resolves the related model from the row's guard_name, which is
     * absent on the blank instance Eloquent builds for withCount('users').
     * Naming the model directly keeps aggregate queries working.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key'),
        );
    }
}
