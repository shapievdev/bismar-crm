<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Actions\User\ChangeEmployment;
use App\Enums\DismissalReason;
use App\Enums\Permission;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Увольнение.
 *
 * Запись остаётся со всем, что за ней числится, — платформа для человека
 * закрывается: вход, открытые сессии, уведомления на телефон. Кого именно этот
 * человек вправе уволить, решает ChangeEmployment, и здесь это не повторяется.
 *
 * День увольнения можно указать сразу: кнопка на экране ставит сегодняшний, а в
 * разговоре с кадровиком речь чаще о дне, который уже прошёл. Поставить его
 * вторым вызовом тоже можно — staff_set_dismissal_date.
 */
#[Name('staff_dismiss')]
#[Title('Уволить сотрудника')]
#[Description('Увольняет сотрудника: вход закрывается, открытые сессии обрываются, уведомления отключаются. Запись остаётся — уволенного можно вернуть в строй (staff_reinstate). Причину лучше указать: без неё человек не попадёт в доли причин ухода в отчёте о штате. Действие заметно всей компании — сначала подтвердите его у сотрудника.')]
final class DismissStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageUsers);
    }

    public function handle(Request $request, ChangeEmployment $employment): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ManageUsers);

        $input = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reason' => ['nullable', 'in:'.implode(',', array_column(DismissalReason::cases(), 'value'))],
            'dismissed_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $person = $this->person((int) $input['employee_id']);

        return $this->attempt(function () use ($actor, $employment, $input, $person): ResponseFactory {
            $dismissed = $employment->dismiss(
                $person,
                $actor,
                ($input['reason'] ?? null) === null ? null : DismissalReason::from((string) $input['reason']),
            );

            // День ухода — тем же действием, что и правка: увольнение ставит
            // сегодняшний, а назвать могли вчерашний.
            if (($input['dismissed_at'] ?? null) !== null) {
                $dismissed = $employment->correctDismissalDate(
                    $dismissed,
                    $actor,
                    CarbonImmutable::parse((string) $input['dismissed_at']),
                );
            }

            return Response::structured([
                'dismissed' => StaffCard::full($dismissed->load('departments', 'mentor', 'staffTags')),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->required()->description('Номер сотрудника.'),
            'reason' => $schema->string()
                ->enum(DismissalReason::class)
                ->description('Причина ухода: own — по собственному, agreement — по соглашению, employer — по инициативе компании, probation-failed — не прошёл испытательный, no-show — не вышел, other — иное.'),
            'dismissed_at' => $schema->string()
                ->description('День увольнения, ГГГГ-ММ-ДД, не в будущем. Без него ставится сегодняшний.'),
        ];
    }
}
