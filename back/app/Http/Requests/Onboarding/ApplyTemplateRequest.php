<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\ScheduleAnchor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Наложение шаблона на личный маршрут: откуда считать дни.
 *
 * Самой даты здесь может и не быть — она нужна только отсчёту «от выбранной
 * даты»; остальные берут её у сотрудника, у календаря или не берут вовсе.
 */
final class ApplyTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'anchor' => ['required', Rule::enum(ScheduleAnchor::class)],
            'date' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $anchor = ScheduleAnchor::tryFrom((string) $this->input('anchor'));

            if ($anchor?->needsDate() === true && $this->input('date') === null) {
                $validator->errors()->add('date', 'Выберите дату, от которой считать дни.');
            }
        });
    }
}
