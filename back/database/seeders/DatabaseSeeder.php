<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
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

        User::firstOrCreate(
            ['email' => 'admin@bismar.test'],
            ['name' => 'Администратор', 'password' => 'password'],
        )->syncRoles(Role::Admin->value);

        $this->call(KnowledgeSeeder::class);
    }
}
