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
                // SVG is deliberately absent — it can carry script, and a
                // viewer opening it would run that script in the storage
                // origin alongside every other file in the bucket.
                'mimes:pdf,doc,docx,rtf,odt,xls,xlsx,ods,csv,ppt,pptx,odp,txt,md,'
                .'png,jpg,jpeg,gif,webp,heic,'
                .'mp4,webm,mov,mp3,wav,m4a,'
                .'zip,7z,rar',
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
