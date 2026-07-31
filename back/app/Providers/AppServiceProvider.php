<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAdministratorBypass();
    }

    /**
     * Administrators pass every authorisation check.
     *
     * This keeps a newly added permission from silently locking administrators
     * out before the seeder has run. Returning null for everyone else lets the
     * normal gates and policies decide.
     *
     * Note: this bypasses policies wholesale, so a rule that must hold even for
     * administrators cannot live in a policy — enforce it in the action itself.
     */
    private function registerAdministratorBypass(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole(Role::Admin->value) ? true : null);
    }
}
