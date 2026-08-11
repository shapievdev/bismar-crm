<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
                'max:'.$this->maxKilobytes(),
                // An allow-list, not a deny-list: anything not named here is
                // rejected, so a new dangerous extension is refused by default.
                // SVG is deliberately absent — it can carry script, and a
                // viewer opening it would run that script in the storage
                // origin alongside every other file in the bucket.
                // HTML is here because it is useful, and safe only because the
                // signed URL forces a download — see AttachmentDelivery.
                'mimes:pdf,doc,docx,rtf,odt,xls,xlsx,ods,csv,ppt,pptx,odp,txt,md,html,htm,'
                .'png,jpg,jpeg,gif,webp,heic,'
                .'mp4,webm,mov,mp3,wav,m4a,'
                .'zip,7z,rar',
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * How large this particular upload may be.
     *
     * Video gets the same room as a lesson's own recording. An article embeds
     * its video as an ordinary attachment, so charging it the ordinary
     * attachment limit would cap articles far below what the lesson's video
     * slot allows — for no reason other than which endpoint it arrived at.
     */
    private function maxKilobytes(): int
    {
        $file = $this->file('file');

        $isVideo = $file instanceof UploadedFile
            && str_starts_with((string) $file->getMimeType(), 'video/');

        return (int) config($isVideo ? 'lms.video_max_kb' : 'lms.attachment_max_kb');
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
