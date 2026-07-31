<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

final readonly class RegisterUser
{
    /**
     * Self-registered users start with the least privileged role; an
     * administrator promotes them afterwards.
     */
    private const DEFAULT_ROLE = Role::Viewer;

    public function handle(RegisterData $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            $user->assignRole(self::DEFAULT_ROLE->value);

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
