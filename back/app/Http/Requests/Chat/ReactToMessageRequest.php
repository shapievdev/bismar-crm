<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Support\Chat\Reactions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклик на реплику: один знак из закрытого набора.
 *
 * Набор проверяется здесь, а не только на экране: вкладка выбирает знак из
 * подсказки, но запрос может прийти и не от неё, а под сообщением руководителя
 * не должно оказаться того, чего там быть не должно.
 */
final class ReactToMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(Reactions::ALLOWED)],
        ];
    }

    public function emoji(): string
    {
        return (string) $this->validated('emoji');
    }
}
