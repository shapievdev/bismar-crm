<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // The first account is a superadmin: it is the only role that can hand
        // out administrator rights, so a system without one can never grow.
        User::firstOrCreate(
            ['email' => 'admin@bismar.test'],
            ['first_name' => 'Администратор', 'password' => 'password'],
        )->syncRoles(AccessLevel::SuperAdmin->value);

        $this->call(LmsSeeder::class);
    }
}
