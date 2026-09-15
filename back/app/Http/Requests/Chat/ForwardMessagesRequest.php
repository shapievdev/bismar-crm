<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Пересылка: что берём и куда кладём.
 *
 * Принадлежность реплик исходной переписке проверяется правилом, а не в
 * приложении, и по той же причине, что и у ответа: между проверкой и вставкой
 * помещается чужой запрос, а переслать чужой разговор, подставив к своему
 * номера из соседнего, не должно получаться и в эту щель.
 *
 * Право писать в переписку-получателя проверяется политикой в контроллере: тут
 * известно только, что такая переписка есть.
 */
final class ForwardMessagesRequest extends FormRequest
{
    /** Сколько реплик уходит за раз. Столько же выделяется в ленте. */
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

            'to_conversation_id' => ['required', 'integer', Rule::exists('conversations', 'id')],
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

    public function targetId(): int
    {
        return (int) $this->validated('to_conversation_id');
    }
}
