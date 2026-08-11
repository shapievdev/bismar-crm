<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Сообщение: текст, файлы или и то и другое.
 */
final class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:5'],

            // Двадцать мегабайт на файл: переписка — не хранилище, крупное
            // кладут в материалы урока, где для этого есть место.
            'attachments.*' => ['file', 'max:20480'],
        ];
    }

    /**
     * Пустое сообщение отправить нельзя: ни текста, ни файла — значит, нажали
     * «отправить» случайно.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (trim((string) $this->input('body')) === '' && $this->allFiles() === []) {
                    $validator->errors()->add('body', 'Нечего отправлять.');
                }
            },
        ];
    }

    public function body(): ?string
    {
        $body = trim((string) $this->validated('body'));

        return $body === '' ? null : $body;
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        /** @var list<UploadedFile> $files */
        $files = $this->file('attachments', []);

        return array_values($files);
    }
}
