<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\SalesPlanKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * План на месяц: на что, сколько и за какой месяц.
 *
 * Месяц приходит как `2026-10` — числа в нём нет и быть не должно: план ставят
 * на месяц целиком, и дата внутри него означала бы, что бывает план на
 * семнадцатое.
 */
final class PlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'kind' => ['required', 'string', Rule::in(SalesPlanKind::values())],

            // Что из двух обязательно, решает вид: у плана отдела и норматива
            // цель — отдел, у личного — продавец.
            'department_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
                Rule::requiredIf(fn (): bool => $this->string('kind')->value() !== SalesPlanKind::Person->value),
            ],
            'sales_person_id' => [
                'nullable',
                'integer',
                'exists:sales_people,id',
                Rule::requiredIf(fn (): bool => $this->string('kind')->value() === SalesPlanKind::Person->value),
            ],

            // Ноль разрешён: «план не ставим» — тоже решение, и отличается от
            // «плана ещё нет». Снимают план удалением, а не нулём.
            'revenue' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.regex' => 'Месяц передаётся как 2026-10.',
            'kind.in' => 'Неизвестный вид плана.',
            'department_id.required' => 'План отдела и норматив ставятся на отдел.',
            'sales_person_id.required' => 'Личный план ставится на продавца.',
            'revenue.required' => 'Не указана сумма плана.',
        ];
    }

    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->string('period')->value().'-01')
            ->startOfMonth();
    }

    public function kind(): SalesPlanKind
    {
        return SalesPlanKind::from($this->string('kind')->value());
    }
}
