<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

final readonly class RegisterUser
{
    public function handle(RegisterData $data): User
    {
        $user = User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        event(new Registered($user));

        return $user;
    }
}
