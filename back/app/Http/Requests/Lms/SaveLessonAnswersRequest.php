<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\AnswerSource;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveLessonAnswersRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array', 'max:200'],
            'answers.*.question' => ['required', 'string', 'max:1000'],
            'answers.*.answer' => ['required', 'string', 'max:10000'],
            'answers.*.source_kind' => ['required', Rule::enum(AnswerSource::class)],

            'answers.*.source_attachment_id' => ['nullable', 'integer'],
            // Час записи — 3600 секунд; предел взят с большим запасом, чтобы не
            // отвергать длинные вебинары, но отсечь явную опечатку.
            'answers.*.source_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'answers.*.source_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'answers.*.source_block_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Проверяет, что указанное место вообще существует в этом уроке.
     *
     * Без этого ссылка ведёт в никуда, и узнают об этом не здесь, а через месяц
     * — когда сотрудник нажмёт на источник в ответе консультанта и никуда не
     * попадёт. Ошибка молчаливая и оттого дорогая, поэтому ловится на входе.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lesson = $this->route('lesson');

            if (! $lesson instanceof Lesson) {
                return;
            }

            $attachments = $lesson->attachments()->pluck('id')->all();
            $blocks = $lesson->blockIds();

            /** @var array<int, array<string, mixed>> $answers */
            $answers = $this->input('answers', []);

            foreach ($answers as $index => $answer) {
                $kind = AnswerSource::tryFrom((string) ($answer['source_kind'] ?? ''));

                match ($kind) {
                    AnswerSource::Attachment => $this->checkAttachment($validator, $index, $answer, $attachments),
                    AnswerSource::Video => $this->checkVideo($validator, $index, $lesson),
                    AnswerSource::Text => $this->checkBlock($validator, $index, $answer, $blocks),
                    null => null,
                };
            }
        });
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<int>  $attachments
     */
    private function checkAttachment(Validator $validator, int $index, array $answer, array $attachments): void
    {
        $id = $answer['source_attachment_id'] ?? null;

        if ($id === null) {
            $validator->errors()->add("answers.{$index}.source_attachment_id", 'Выберите файл.');

            return;
        }

        // Принадлежность именно этому уроку, а не просто существование: иначе
        // строка сошлётся на файл чужого урока, до которого читатель может не
        // иметь доступа.
        if (! in_array((int) $id, $attachments, strict: true)) {
            $validator->errors()->add(
                "answers.{$index}.source_attachment_id",
                'Этот файл не приложен к уроку.',
            );
        }
    }

    private function checkVideo(Validator $validator, int $index, Lesson $lesson): void
    {
        if (! $lesson->hasVideo()) {
            $validator->errors()->add(
                "answers.{$index}.source_kind",
                'У урока нет видео, на которое можно сослаться.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $blocks
     */
    private function checkBlock(Validator $validator, int $index, array $answer, array $blocks): void
    {
        $block = $answer['source_block_id'] ?? null;

        // Пустое значение допустимо: это ссылка на текст урока целиком, что
        // разумно для короткой статьи, где указывать абзац нечего.
        if ($block === null || $block === '') {
            return;
        }

        if (! in_array((string) $block, $blocks, strict: true)) {
            $validator->errors()->add(
                "answers.{$index}.source_block_id",
                'Этого фрагмента в тексте урока больше нет — укажите место заново.',
            );
        }
    }
}
