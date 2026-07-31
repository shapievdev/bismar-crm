<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles and permissions are part of the application's baseline rather than
     * test fixtures: almost nothing is reachable without them. RefreshDatabase
     * runs this seeder for every test that refreshes the database.
     */
    protected bool $seed = true;

    protected string $seeder = RolePermissionSeeder::class;
}
