<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Enums\ConversationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Завести переписку: с одним человеком или с несколькими.
 */
final class StartConversationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(ConversationKind::class)],

            // Личная — ровно с одним собеседником.
            'user_id' => [
                Rule::requiredIf(fn (): bool => $this->input('kind') === ConversationKind::Direct->value),
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$this->user()?->getKey()]),
            ],

            // Групповая — с названием и списком приглашённых.
            'title' => [
                Rule::requiredIf(fn (): bool => $this->input('kind') === ConversationKind::Group->value),
                'string',
                'max:120',
            ],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    public function kind(): ConversationKind
    {
        return ConversationKind::from((string) $this->validated('kind'));
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->validated('user_ids', []);

        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
