<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class StoreVideoRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'video' => [
                'required',
                'file',
                'max:'.config('lms.video_max_kb'),
                // Formats a browser can play natively. Anything else would
                // upload fine and then fail to play, which is worse.
                'mimetypes:video/mp4,video/webm,video/quicktime',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video.max' => 'Видео слишком большое. Максимум :max КБ.',
            'video.mimetypes' => 'Поддерживаются MP4, WebM и MOV.',
        ];
    }
}
