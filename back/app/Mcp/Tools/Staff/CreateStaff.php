<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\CreateUser;
use App\Data\User\NewUserData;
use App\Enums\Permission;
use App\Enums\WorkMode;
use App\Http\Requests\User\StaffFieldMessages;
use App\Http\Requests\User\StoreUserRequest;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use App\Support\Email;
use App\Support\Phone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Завести сотрудника.
 *
 * Правила — те самые, что проверяет форма (StoreUserRequest::rulesFor): номер
 * телефона приводится к хранимому виду и обязан быть свободным, пароль проходит
 * общую проверку на стойкость. Второго списка правил здесь нет намеренно: он
 * разошёлся бы с формой, и коннектор начал бы заводить людей, которых экран
 * завести не даёт.
 *
 * Прав новичку не достаётся никаких — как и при заведении с экрана. Доступ
 * ставят вторым, осознанным движением (staff_set_access): учётная запись,
 * приходящая пустой, не может случайно оказаться с лишним.
 */
#[Name('staff_create')]
#[Title('Завести сотрудника')]
#[Description('Создаёт учётную запись сотрудника. Телефон обязателен — это логин, и он должен быть свободен; пароль обязателен, не короче восьми знаков, и его нужно передать человеку. Прав новая запись не получает никаких: доступ выдаётся отдельно инструментом staff_set_access.')]
final class CreateStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, CreateUser $createUser): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageUsers);

        // Номер и почта — к хранимому виду до проверки: «8 (999)…» и «+7 999…»
        // это один номер, и правилу должен достаться он, а не то, как его
        // набрали. То же делает форма в prepareForValidation.
        $request->merge([
            'phone' => Phone::normalize($request->get('phone')),
            'email' => Email::normalize($request->get('email')),
        ]);

        $data = Validator::make(
            $request->all(),
            StoreUserRequest::rulesFor(),
            StaffFieldMessages::all(),
        )->validate();

        /** @var array{last_name: string, first_name: string, middle_name?: string|null, email?: string|null, phone: string, job_title?: string|null, hired_at?: string|null, work_mode?: string|null, password: string} $data */
        $person = $createUser->handle(NewUserData::fromArray($data));

        return Response::structured([
            'created' => StaffCard::full($person->load('roles', 'permissions', 'departments', 'mentor', 'staffTags')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'last_name' => $schema->string()->required()->description('Фамилия.'),
            'first_name' => $schema->string()->required()->description('Имя.'),
            'middle_name' => $schema->string()->description('Отчество, если есть.'),
            'phone' => $schema->string()
                ->required()
                ->description('Телефон — логин сотрудника. Российский номер в любом виде: 8 999 123-45-67 или +79991234567.'),
            'email' => $schema->string()->description('Почта. Необязательна: входят по телефону.'),
            'job_title' => $schema->string()->description('Должность — строка, а не справочник. Так, как её называют в компании.'),
            'hired_at' => $schema->string()
                ->description('День приёма, ГГГГ-ММ-ДД. Не в будущем. Без него человек не попадёт ни в стаж, ни в текучесть, так что лучше спросить.'),
            'work_mode' => $schema->string()
                ->enum(WorkMode::class)
                ->description('Режим работы: shift — смена в зале, office — офис.'),
            'password' => $schema->string()
                ->required()
                ->description('Первый пароль, который передадут сотруднику. Не короче восьми знаков.'),
        ];
    }
}
