<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Support\Chat\VoiceRecording;
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

            /*
             * Кого позвали по имени. Номерами, а не разбором текста: имя в теле
             * реплики человек мог набрать руками, а позвать — не позвать.
             * Посторонних отсеивает само действие: состав переписки ему виден,
             * а правилу проверки — нет.
             */
            'mentions' => ['array', 'max:50'],
            'mentions.*' => ['integer'],

            /*
             * Надиктованная запись: сам файл идёт обычным вложением, а здесь —
             * то, что о нём знает только браузер. Длительность и волну он уже
             * посчитал, чтобы рисовать её во время диктовки; считать то же самое
             * на сервере значило бы распаковывать звук средствами PHP.
             */
            'voice' => ['nullable', 'array'],
            'voice.duration_ms' => ['required_with:voice', 'integer', 'min:1'],
            'voice.waveform' => ['required_with:voice', 'array', 'max:200'],
            'voice.waveform.*' => ['integer', 'between:0,100'],
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

            /*
             * Надиктованное — это ровно одна запись и ничего больше.
             *
             * Без этой проверки числами голосового подписался бы любой файл:
             * приложили архив, назвали его записью — и в ленте у него волна с
             * кнопкой «играть», под которой нечего играть.
             */
            function (Validator $validator): void {
                if ($this->input('voice') === null) {
                    return;
                }

                $files = $this->attachments();

                if (count($files) !== 1 || ! $this->soundsLikeSpeech($files[0])) {
                    $validator->errors()->add('voice', 'Голосовое — это одна звуковая запись.');
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
     * Кого назвали по имени.
     *
     * @return list<int>
     */
    public function mentions(): array
    {
        $called = $this->validated('mentions');

        return is_array($called) ? array_values(array_map(intval(...), $called)) : [];
    }

    /**
     * Звук ли это.
     *
     * Спрашиваем дважды и соглашаемся на любое «да». Тип, названный клиентом,
     * ненадёжен: webm — контейнер и для звука, и для видео, и по расширению его
     * опознают как видео, даже когда внутри одна дорожка с голосом. Тип,
     * угаданный по содержимому, ненадёжен иначе — он не видит разницы между
     * записанным здесь и присланным откуда-то.
     *
     * Строгость тут и не нужна: врать этим можно только себе. Подпись «голос»
     * на архиве испортит вид одному отправителю, а не откроет никому ничего —
     * поэтому проверка отсекает явную ошибку, а не злой умысел.
     */
    private function soundsLikeSpeech(?UploadedFile $file): bool
    {
        if ($file === null) {
            return false;
        }

        foreach ([$file->getClientMimeType(), $file->getMimeType()] as $type) {
            if (str_starts_with((string) $type, 'audio/')) {
                return true;
            }
        }

        return false;
    }

    /** Числа надиктованной записи — если реплика вообще надиктована. */
    public function voice(): ?VoiceRecording
    {
        $voice = $this->validated('voice');

        if (! is_array($voice)) {
            return null;
        }

        return VoiceRecording::from((int) $voice['duration_ms'], (array) $voice['waveform']);
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
