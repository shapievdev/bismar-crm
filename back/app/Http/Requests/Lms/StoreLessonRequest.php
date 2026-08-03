<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLessonRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            // The editor's node tree. Its shape is the editor's business; we
            // only insist it is a document, and derive plain text from it.
            'content_json' => ['nullable', 'array'],
            'content_json.type' => ['required_with:content_json', 'string', 'in:doc'],
            'content_json.content' => ['nullable', 'array'],
            // Only http(s) links: a javascript: or data: URL would be rendered
            // into an embed and become a script-injection vector.
            'video_url' => ['nullable', 'url:http,https', 'max:2048'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
