<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use App\Enums\BroadcastAudience;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Рассылка уведомлений: текст и адресаты.
 *
 * Длина ограничена не базой, а экраном телефона: заголовок длиннее строки и
 * текст длиннее трёх система обрежет сама, и лучше сказать об этом автору
 * заранее, чем показать ему обрубок.
 */
final class SendBroadcastRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $audience = $this->input('audience');

        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],

            // Путь внутри приложения, а не любой адрес: уведомление ведёт к
            // себе домой, и ссылка на сторонний сайт из него — не рассылка, а
            // способ увести человека куда угодно.
            'url' => ['nullable', 'string', 'max:255', 'regex:/^\/[\w\-\/?=&.%]*$/'],

            'audience' => ['required', Rule::enum(BroadcastAudience::class)],

            'user_ids' => [Rule::requiredIf($audience === BroadcastAudience::Selected->value), 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists(User::class, 'id')->whereNull('dismissed_at')],

            'department_id' => [
                Rule::requiredIf($audience === BroadcastAudience::Department->value),
                'nullable',
                'integer',
                Rule::exists(Department::class, 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.regex' => 'Ссылка должна быть путём внутри приложения — например, /news.',
            'user_ids.required' => 'Выберите, кому отправить.',
            'department_id.required' => 'Выберите отдел.',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     body: string,
     *     url: ?string,
     *     audience: BroadcastAudience,
     *     user_ids: list<int>,
     *     department_id: ?int
     * }
     */
    public function toAttributes(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('user_ids', []);

        return [
            'title' => trim((string) $this->validated('title')),
            'body' => trim((string) $this->validated('body')),
            'url' => $this->validated('url') ?: null,
            'audience' => BroadcastAudience::from((string) $this->validated('audience')),
            'user_ids' => array_values(array_unique(array_map(intval(...), $ids))),
            'department_id' => $this->validated('department_id') === null
                ? null
                : (int) $this->validated('department_id'),
        ];
    }
}
