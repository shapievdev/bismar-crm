<?php

declare(strict_types=1);

namespace App\Http\Requests\News;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Новость целиком — и когда её заводят, и когда правят.
 *
 * Один класс на оба случая: адрес новости с клиента не приходит вовсе (его
 * выдаёт SaveNews и больше не меняет), а всё остальное проверяется одинаково.
 */
final class SaveNewsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],

            // Строка для карточки в ленте. Не обязательна: у короткой новости
            // и заголовка довольно.
            'excerpt' => ['nullable', 'string', 'max:500'],

            // Документ редактора блоков — тот же формат, что у урока.
            'content_json' => ['nullable', 'array'],

            'status' => ['required', Rule::enum(NewsStatus::class)],
            'is_pinned' => ['sometimes', 'boolean'],
            'audience' => ['required', Rule::enum(NewsAudience::class)],
            'requires_acknowledgement' => ['sometimes', 'boolean'],

            // Поимённый список нужен только адресной новости, и пустым он там
            // быть не может: новость без единого адресата не увидит никто.
            'recipients' => $this->isAddressed()
                ? ['required', 'array', 'min:1']
                : ['sometimes', 'array'],
            'recipients.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    private function isAddressed(): bool
    {
        return $this->input('audience') === NewsAudience::Selected->value;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.required' => 'Выберите, кому адресована новость.',
            'recipients.min' => 'Выберите хотя бы одного сотрудника.',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     excerpt: ?string,
     *     content_json: ?array<string, mixed>,
     *     status: string,
     *     is_pinned: bool,
     *     audience: string,
     *     requires_acknowledgement: bool,
     *     recipients: list<int>
     * }
     */
    public function toAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'title' => (string) $validated['title'],
            'excerpt' => $validated['excerpt'] ?? null,
            'content_json' => $validated['content_json'] ?? null,
            'status' => (string) $validated['status'],
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'audience' => (string) $validated['audience'],
            'requires_acknowledgement' => (bool) ($validated['requires_acknowledgement'] ?? false),
            'recipients' => array_values(array_map(intval(...), $validated['recipients'] ?? [])),
        ];
    }
}
