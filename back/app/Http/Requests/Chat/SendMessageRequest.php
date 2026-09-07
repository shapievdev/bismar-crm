<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Support\Lms\MaterialReference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

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

            /*
             * Отвечать можно только на реплику из этой же переписки — иначе по
             * ответу утекало бы содержимое чужого разговора: цитата приходит
             * вместе с ответом, и проверки на чтение той переписки уже никто не
             * делает. Условие идёт в запрос, а не в приложение: разговор мог
             * поменяться между проверкой и вставкой.
             */
            /*
             * Материал, с которого пишут: с карточки ответственного уходят в
             * мессенджер, и без этого адресат читает вопрос, не понимая, о чём
             * он. Приходят только вид и номер — название и адрес собираются на
             * сервере, иначе карточкой над репликой можно было бы объявить что
             * угодно.
             */
            'about' => ['nullable', 'array'],
            'about.kind' => ['required_with:about', 'string', Rule::in(MaterialReference::KINDS)],
            'about.id' => ['required_with:about', 'integer'],

            'reply_to_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')
                    ->where('conversation_id', $this->route('conversation')?->getKey())
                    ->whereNull('deleted_at'),
            ],
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

    public function replyToId(): ?int
    {
        $id = $this->validated('reply_to_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Материал, названный в запросе, — если он назван и если он есть.
     *
     * Несуществующий не ошибка: ссылку могли прислать на выброшенный документ,
     * и разговор из-за этого начаться должен всё равно — просто без карточки.
     */
    public function about(): ?MaterialReference
    {
        $about = $this->validated('about');

        if (! is_array($about)) {
            return null;
        }

        return MaterialReference::find((string) $about['kind'], (int) $about['id']);
    }
}
