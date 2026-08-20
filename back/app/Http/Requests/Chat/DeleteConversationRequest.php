<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Enums\DeletionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Удалить переписку — у себя или у всех.
 *
 * Умолчание — «у себя», и это не лень, а осторожность: удаление у всех
 * необратимо, и запрос, забывший сказать, чего он хочет, должен получить
 * меньшее из двух.
 */
final class DeleteConversationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::enum(DeletionScope::class)],
        ];
    }

    public function scope(): DeletionScope
    {
        $scope = $this->validated('scope');

        return $scope === null ? DeletionScope::Mine : DeletionScope::from((string) $scope);
    }
}
