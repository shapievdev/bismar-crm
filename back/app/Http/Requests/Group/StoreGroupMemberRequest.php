<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Кого добавляют в группу.
 *
 * Списком, а не по одному: людей набирают подряд, и запрос на каждого означал
 * бы, что половина состава добавилась, а половина нет.
 */
final class StoreGroupMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],

            // Уволенных в группу не зовут: она отвечает на вопрос «кого
            // позвать», и ушедший в ней только сбивал бы счёт.
            'user_ids.*' => ['integer', Rule::exists(User::class, 'id')->whereNull('dismissed_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Выберите, кого добавить.',
        ];
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
}
