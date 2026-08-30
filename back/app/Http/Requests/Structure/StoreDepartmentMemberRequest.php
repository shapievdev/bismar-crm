<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Enums\DepartmentRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Кого добавить в отдел и кем.
 *
 * Списком, а не по одному: людей набирают в отдел пачкой, и по одному запросу
 * на каждого экран моргал бы половиной состава.
 */
final class StoreDepartmentMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            // Уволенных в структуру не ставят: она о том, кто работает сейчас.
            'user_ids.*' => ['integer', Rule::exists(User::class, 'id')->whereNull('dismissed_at')],
            'role' => ['required', Rule::enum(DepartmentRole::class)],

            // Откуда человек пришёл — при перетаскивании из другого отдела.
            // Необязательно: добавляют людей и просто так, ниоткуда.
            'from_department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
        ];
    }

    /**
     * Отдел, из которого человека забирают. Пусто — значит его не забирают
     * ниоткуда, а просто приписывают сюда: числиться в двух отделах разом
     * можно, и перенос от добавления отличает только это поле.
     */
    public function fromDepartmentId(): ?int
    {
        $id = $this->validated('from_department_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('user_ids', []);

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    public function role(): DepartmentRole
    {
        return DepartmentRole::from((string) $this->validated('role'));
    }
}
