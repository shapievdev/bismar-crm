<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Кому и какому отделу принадлежит имя из витрины.
 *
 * Имя не проверяется против выгрузки намеренно. Во-первых, сервер аналитики
 * может быть недоступен, а править связи это мешать не должно. Во-вторых,
 * сопоставление заводят и наперёд — на продавца, который выйдет в понедельник
 * и в годовой выборке ещё не появился.
 */
final class SellerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_name' => ['required', 'string', 'max:255'],

            // Оба необязательны и оба обнуляемы: снять сопоставление — такое
            // же законное действие, как поставить.
            'department_id' => ['present', 'nullable', 'integer', 'exists:departments,id'],
            'user_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_name.required' => 'Не указано имя продавца из выгрузки.',
            'department_id.exists' => 'Такого отдела в структуре нет.',
            'user_id.exists' => 'Такого сотрудника нет.',
        ];
    }

    public function externalName(): string
    {
        return trim($this->string('external_name')->value());
    }

    public function departmentId(): ?int
    {
        return $this->input('department_id') === null ? null : $this->integer('department_id');
    }

    public function userId(): ?int
    {
        return $this->input('user_id') === null ? null : $this->integer('user_id');
    }
}
