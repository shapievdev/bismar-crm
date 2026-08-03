<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAttachmentRequest extends FormRequest
{
    /**
     * Only the caption is editable — replacing the file itself means a new
     * upload, so the stored object and its row never drift apart.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
