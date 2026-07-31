<?php

declare(strict_types=1);

namespace App\Support;

final class Authorization
{
    /**
     * The guard every role and permission is stored under.
     *
     * This is pinned deliberately. `auth:sanctum` calls Auth::shouldUse(), which
     * rewrites `auth.defaults.guard` to "sanctum" for the rest of the request —
     * so resolving the guard dynamically would look for roles under "sanctum"
     * at request time while the seeder wrote them under "web" from the CLI, and
     * every permission check would quietly fail.
     */
    public const GUARD = 'web';
}
