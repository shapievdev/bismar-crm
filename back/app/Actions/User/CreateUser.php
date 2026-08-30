<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Data\User\NewUserData;
use App\Models\User;

final readonly class CreateUser
{
    /**
     * Adds a colleague, with nothing granted.
     *
     * Access is a second, deliberate step: an account that arrives empty cannot
     * accidentally be created with more than someone meant to give it.
     */
    public function handle(NewUserData $data): User
    {
        return User::create([
            'last_name' => $data->lastName,
            'first_name' => $data->firstName,
            'middle_name' => $data->middleName,
            'email' => $data->email,
            'phone' => $data->phone,
            'job_title' => $data->jobTitle,
            'password' => $data->password,
        ]);
    }
}
