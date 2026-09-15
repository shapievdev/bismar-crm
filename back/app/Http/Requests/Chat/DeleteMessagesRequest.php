<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Удаление выделенного: несколько реплик разом.
 *
 * Принадлежность этой же переписке — правилом запроса: удалить чужое, подставив
 * к своему разговору номера из соседнего, не должно получаться. Кому из
 * выделенного что можно, решает уже действие — и решает до того, как удалит
 * первое.
 */
final class DeleteMessagesRequest extends FormRequest
{
    /** Столько же, сколько выделяется в ленте. */
    public const MAX = 30;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'message_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'message_ids.*' => [
                'integer',
                Rule::exists('messages', 'id')
                    ->where('conversation_id', $this->route('conversation')?->getKey())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function messageIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('message_ids');

        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
