<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Enums\EmploymentStatus;
use App\Enums\Permission;
use App\Enums\WorkMode;
use App\Http\Requests\User\StaffFieldMessages;
use App\Http\Requests\User\UpdateUserRequest;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use App\Models\User;
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
 * Правка карточки сотрудника.
 *
 * Карточка правится целиком, как и формой: что не прислали, то и стирается —
 * отчество, почта, должность, дата приёма, наставник. Это свойство формы, а не
 * оплошность: «пусто» в ней значит «нет такого», а не «оставить прежнее».
 *
 * Для коннектора это ловушка: модель охотно пришлёт одно поле и сотрёт
 * остальные. Поэтому незаполненные поля здесь берутся из нынешней карточки, а
 * стереть значение можно, прислав пустую строку, — и об этом сказано в описании
 * каждого поля. Правкой целиком это быть не перестаёт: правила те же
 * (UpdateUserRequest::rulesFor), и «работает» по-прежнему нельзя заменить на
 * «уволен» — для этого есть staff_dismiss со своими правилами.
 */
#[Name('staff_update')]
#[Title('Поправить карточку сотрудника')]
#[Description('Правит сведения о сотруднике: имя, телефон, почту, должность, дату приёма, режим работы, наставника, положение (работает, декрет, исполняет обязанности) и пароль. Присылайте только те поля, которые меняете, — остальные останутся прежними. Чтобы стереть необязательное поле, пришлите пустую строку. Уволить этим инструментом нельзя: для увольнения есть staff_dismiss.')]
final class UpdateStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageUsers);

        $person = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        $data = Validator::make(
            $this->fillGaps($request, $person),
            UpdateUserRequest::rulesFor($person),
            StaffFieldMessages::all(),
        )->validate();

        $person->update([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'job_title' => $data['job_title'],
            'hired_at' => $data['hired_at'],
            'work_mode' => $data['work_mode'],
            'mentor_id' => $data['mentor_id'],
            'employment_status' => $data['employment_status'] ?? EmploymentStatus::Working,
        ]);

        // Пароль — только если прислали: пустое значит «не менять», а не
        // «очистить», и войти без пароля было бы нельзя.
        if (($data['password'] ?? null) !== null && $data['password'] !== '') {
            $person->update(['password' => $data['password']]);
        }

        return Response::structured([
            'updated' => StaffCard::full(
                $person->refresh()->load('roles', 'permissions', 'departments', 'mentor', 'staffTags'),
            ),
        ]);
    }

    /**
     * Непришедшие поля — из нынешней карточки.
     *
     * Пустая строка при этом проходит как есть: ею и стирают. Отличить
     * «не прислал» от «прислал пустое» можно только так — по наличию ключа.
     *
     * @return array<string, mixed>
     */
    private function fillGaps(Request $request, User $person): array
    {
        $sent = $request->all();

        $current = [
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'phone' => $person->phone,
            'email' => $person->email,
            'job_title' => $person->job_title,
            'hired_at' => $person->hired_at?->toDateString(),
            'work_mode' => $person->work_mode?->value,
            'mentor_id' => $person->mentor_id,
            'employment_status' => $person->employment_status?->value,
        ];

        $data = array_merge($current, array_intersect_key($sent, $current));

        // Пустые строки — в «ничего»: правило `email` забракует пустую строку,
        // хотя стереть адрес ею как раз и просят. То же делает форма.
        foreach (['middle_name', 'job_title', 'hired_at', 'work_mode', 'employment_status'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        $data['phone'] = Phone::normalize($data['phone']);
        $data['email'] = Email::normalize($data['email']);

        if (array_key_exists('password', $sent)) {
            $data['password'] = $sent['password'];
        }

        // «Убрать наставника» приходит нулём или пустой строкой.
        if (($data['mentor_id'] ?? null) === '' || ($data['mentor_id'] ?? null) === 0) {
            $data['mentor_id'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
            'last_name' => $schema->string()->description('Фамилия.'),
            'first_name' => $schema->string()->description('Имя.'),
            'middle_name' => $schema->string()->description('Отчество. Пустая строка — стереть.'),
            'phone' => $schema->string()->description('Телефон (он же логин). Стереть нельзя.'),
            'email' => $schema->string()->description('Почта. Пустая строка — стереть.'),
            'job_title' => $schema->string()->description('Должность. Пустая строка — стереть.'),
            'hired_at' => $schema->string()->description('День приёма, ГГГГ-ММ-ДД, не в будущем. Пустая строка — стереть.'),
            'work_mode' => $schema->string()->enum(WorkMode::class)->description('Режим работы: shift или office.'),
            'mentor_id' => $schema->integer()->description('Номер наставника — работающего сотрудника, не самого человека. 0 — снять наставника.'),
            'employment_status' => $schema->string()
                ->enum(['working', 'parental-leave', 'acting'])
                ->description('Положение: работает, в декрете, исполняет обязанности. Уволить отсюда нельзя.'),
            'password' => $schema->string()->description('Новый пароль, если его сбрасывают. Не короче восьми знаков.'),
        ];
    }
}
