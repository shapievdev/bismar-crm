<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\ChangeEmployment;
use App\Enums\Permission;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Правка дня увольнения.
 *
 * Отчёт о движении персонала считает по датам, а не по тому, когда нажали
 * кнопку: человек ушёл пятнадцатого, кадровик дошёл до записи двадцатого, и без
 * правки эти пять дней остались бы в численности и в стаже ушедшего.
 *
 * Два предела, и оба не формальность — те же, что у формы: будущим днём не
 * увольняют (приложение считает уволенным всякого, у кого дата стоит), и раньше
 * приёма тоже (отчёт считает стаж разностью этих дат, и перевёрнутая пара дала
 * бы отрицательный стаж, который молча утянул бы средние).
 */
#[Name('staff_set_dismissal_date')]
#[Title('Поправить день увольнения')]
#[Description('Меняет дату увольнения уже уволенного сотрудника. Нужна, когда увольнение оформили позже, чем человек ушёл: отчёт о штате считает по этой дате. Нельзя поставить будущий день и день раньше приёма.')]
final class SetDismissalDate extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, ChangeEmployment $employment): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageUsers);

        $person = $this->person((int) $request->validate([
            'employee_id' => ['required', 'integer'],
        ])['employee_id']);

        // Предел по дате приёма — только когда она известна: в базе есть люди,
        // заведённые до того, как её начали спрашивать, и требовать от кадровика
        // дату пятилетней давности ради правки дня ухода незачем. То же правило,
        // что в UpdateDismissalRequest.
        $hired = $person->hired_at;

        $input = Validator::make($request->all(), [
            'dismissed_at' => array_values(array_filter([
                'required',
                'date',
                'before_or_equal:today',
                $hired === null ? null : 'after_or_equal:'.$hired->toDateString(),
            ])),
        ], [
            'dismissed_at.before_or_equal' => 'Будущим днём не увольняют: человек либо уже ушёл, либо ещё работает.',
            'dismissed_at.after_or_equal' => 'Уволить раньше, чем приняли, нельзя — проверьте дату приёма в карточке.',
        ])->validate();

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'updated' => StaffCard::full($employment
                ->correctDismissalDate($person, $actor, CarbonImmutable::parse((string) $input['dismissed_at']))
                ->load('departments', 'mentor', 'staffTags')),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер уволенного сотрудника.'),
            'dismissed_at' => $schema->string()
                ->required()
                ->description('День увольнения, ГГГГ-ММ-ДД. Не в будущем и не раньше дня приёма.'),
        ];
    }
}
