<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\AnswerSource;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveTranscriptRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_kind' => ['required', Rule::enum(AnswerSource::class)],
            'source_attachment_id' => ['nullable', 'integer'],
            'source_block_id' => ['nullable', 'string', 'max:64'],

            // Одно из двух: файл расшифровки или вставленный текст.
            //
            // По расширению, а не по MIME-типу: субтитры браузеры объявляют то
            // text/plain, то application/x-subrip, то вовсе octet-stream — и
            // отвергнутый по этому признаку .srt был бы отказом на ровном
            // месте. Что содержимое действительно текст, проверяется ниже.
            'file' => ['nullable', 'file', 'max:'.config('lms.transcript_max_kb'), 'extensions:txt,md,srt,vtt'],
            'text' => ['nullable', 'string', 'max:2000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasFile('file') && trim((string) $this->input('text')) === '') {
                $validator->errors()->add('text', 'Загрузите файл расшифровки или вставьте текст.');
            }

            // Двоичный файл под видом расшифровки прошёл бы разбор молча и лёг
            // бы в поиск нечитаемой кашей. Заметить это потом можно только по
            // странным ответам консультанта.
            if ($this->hasFile('file') && ! mb_check_encoding($this->transcript(), 'UTF-8')) {
                $validator->errors()->add('file', 'Файл не похож на текст в кодировке UTF-8.');
            }

            $lesson = $this->route('lesson');

            if (! $lesson instanceof Lesson) {
                return;
            }

            match (AnswerSource::tryFrom((string) $this->input('source_kind'))) {
                AnswerSource::Attachment => $this->checkAttachment($validator, $lesson),
                AnswerSource::Video => $this->checkVideo($validator, $lesson),
                AnswerSource::Text => $this->checkBlock($validator, $lesson),
                null => null,
            };
        });
    }

    /** Содержимое расшифровки — из файла или из поля, что пришло. */
    public function transcript(): string
    {
        $file = $this->file('file');

        return $file === null
            ? (string) $this->input('text')
            : (string) file_get_contents($file->getRealPath());
    }

    public function originalName(): ?string
    {
        return $this->file('file')?->getClientOriginalName();
    }

    private function checkAttachment(Validator $validator, Lesson $lesson): void
    {
        $id = $this->input('source_attachment_id');

        // Принадлежность именно этому уроку, а не просто существование: иначе
        // расшифровка легла бы на файл чужого урока.
        if ($id === null || ! $lesson->attachments()->whereKey($id)->exists()) {
            $validator->errors()->add('source_attachment_id', 'Этот файл не приложен к уроку.');
        }
    }

    private function checkVideo(Validator $validator, Lesson $lesson): void
    {
        if (! $lesson->hasVideo()) {
            $validator->errors()->add('source_kind', 'У урока нет видео, которое можно расшифровать.');
        }
    }

    /**
     * Расшифровка текста — одна на урок, и абзац у неё не указывается.
     *
     * Место внутри статьи помнит кусок, а не расшифровка: иначе у урока на
     * семьдесят абзацев в списке появлялось бы семьдесят расшифровок, между
     * которыми автору нечего выбирать.
     */
    private function checkBlock(Validator $validator, Lesson $lesson): void
    {
        if (trim((string) $this->input('source_block_id')) !== '') {
            $validator->errors()->add(
                'source_block_id',
                'Расшифровка текста заводится на весь урок целиком, а не на отдельный абзац.',
            );
        }
    }
}
