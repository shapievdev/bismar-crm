<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Data\User\NewUserData;
use App\Models\User;
use App\Support\Chat\GeneralChat;

final readonly class CreateUser
{
    public function __construct(private GeneralChat $general) {}

    /**
     * Adds a colleague, with nothing granted.
     *
     * Access is a second, deliberate step: an account that arrives empty cannot
     * accidentally be created with more than someone meant to give it.
     *
     * Общий чат — исключение, и не из прав, а из устройства: он один на
     * компанию, и новый сотрудник в нём с первого дня. Иначе он узнавал бы об
     * объявлениях последним — после того, как кто-то заметит, что его там нет.
     */
    public function handle(NewUserData $data): User
    {
        $user = User::create([
            'last_name' => $data->lastName,
            'first_name' => $data->firstName,
            'middle_name' => $data->middleName,
            'email' => $data->email,
            'phone' => $data->phone,
            'job_title' => $data->jobTitle,
            'hired_at' => $data->hiredAt,
            'work_mode' => $data->workMode,
            'password' => $data->password,
        ]);

        $this->general->include($user);

        return $user;
    }
}
