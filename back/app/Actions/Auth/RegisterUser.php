<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

final readonly class RegisterUser
{
    /**
     * A self-registered account starts with nothing.
     *
     * There is no default role to fall back on any more: an administrator ticks
     * the permissions this person needs, which is safer than handing out a set
     * nobody asked for.
     */
    public function handle(RegisterData $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'last_name' => $data->lastName,
                'first_name' => $data->firstName,
                'middle_name' => $data->middleName,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
