<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('lms.attachment_max_kb'),
                // An allow-list, not a deny-list: anything not named here is
                // rejected, so a new dangerous extension is refused by default.
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,gif,webp,svg,mp4,webm,mp3,zip',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'Файл слишком большой. Максимум :max КБ.',
            'file.mimes' => 'Такой тип файла загрузить нельзя.',
        ];
    }
}
