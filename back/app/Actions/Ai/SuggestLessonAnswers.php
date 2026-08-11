<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use Anthropic\Client;
use App\Enums\AnswerSource;
use App\Models\Lesson;
use App\Models\TranscriptSegment;
use App\Support\Ai\ModelSettings;

/**
 * Предлагает автору черновик таблицы урока по его расшифровкам.
 *
 * Только по ним. Расшифровка — это всё, что урок содержит, изложенное словами:
 * сказанное в записи, написанное в приложенном документе, набранное в статье.
 * Читать что-то помимо них незачем, а чтение самого текста урока в обход
 * расшифровок дало бы вопросы только по набранному — то есть ровно по той
 * части, которая и так была видна.
 *
 * Ничего не сохраняет: предложения возвращаются наверх и живут в браузере, пока
 * автор не отберёт нужные. Так «в поиск попадает только утверждённое»
 * выполняется само собой — неутверждённой строки просто не существует.
 *
 * Главное следствие: у каждого предложенного вопроса источник известен сразу.
 * Он взят из расшифровки, а та привязана к записи, файлу или блоку — и кусок
 * помнит секунду, на которой сказан. Автору не остаётся ничего проставлять.
 */
final readonly class SuggestLessonAnswers
{
    /**
     * Сколько знаков расшифровки приходится на одну часть.
     *
     * Чем меньше кусок, тем подробнее вопросы: из шести тысяч знаков модель
     * вытягивает десяток общих, из двух — десяток предметных. Но дробить без
     * меры нельзя: ответ, разложенный на два соседних абзаца, в разных частях
     * не соберётся.
     */
    private const CHUNK_CHARS = 3000;

    /**
     * Сколько вопросов просить за один запрос самое большее.
     *
     * Не весь бюджет разом: чем длиннее список, тем охотнее модель сбивается с
     * формата к концу, а обрезанный по лимиту токенов массив JSON не
     * разбирается вовсе — автор получает не «сколько влезло», а ноль.
     */
    private const PER_REQUEST = 12;

    /**
     * Сколько частей разбирать самое большее.
     *
     * Предохранитель от расшифровки на сотню тысяч знаков: без него одно
     * нажатие обошлось бы в три десятка обращений к модели.
     */
    private const MAX_CHUNKS = 12;

    public function __construct(
        private Client $client,
        private ModelSettings $settings,
    ) {}

    /**
     * @return list<array{
     *     question: string,
     *     answer: string,
     *     source_kind: string,
     *     source_attachment_id: int|null,
     *     source_seconds: int|null,
     *     source_page: int|null,
     *     source_block_id: string|null,
     * }>
     */
    public function handle(Lesson $lesson, ?int $transcriptId = null): array
    {
        // Одна расшифровка вместо всех, когда автор просит вопросы у неё.
        // Разбирать урок целиком он будет редко: расшифровки приезжают по
        // одной, и разобрать только что вставленную — обычное действие.
        $segments = TranscriptSegment::query()
            ->where('lesson_id', $lesson->getKey())
            ->when($transcriptId !== null, static fn ($query) => $query->where('transcript_id', $transcriptId))
            ->with('transcript.attachment')
            ->orderBy('transcript_id')
            ->orderBy('position')
            ->get();

        $wanted = (int) config('ai.suggested_questions');
        $chunks = $this->chunks($segments);

        if ($chunks === []) {
            return [];
        }

        // Бюджет вопросов раскладывается по всей расшифровке, а не тратится на
        // её начало. Спрашивая у каждой части сколько влезет, мы выбирали его
        // на первых минутах записи, и до конца дело не доходило никогда.
        $perChunk = min(self::PER_REQUEST, (int) ceil($wanted / count($chunks)));

        $suggestions = [];
        $seen = [];

        foreach ($chunks as $numbered) {
            foreach ($this->askAbout($lesson, $numbered, $perChunk) as $suggestion) {
                // Разные части записи нередко говорят об одном, и повтор того
                // же вопроса другими словами автору только мешает.
                $key = mb_strtolower(preg_replace('/\s+/u', ' ', $suggestion['question']) ?? '');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $suggestions[] = $suggestion;
            }
        }

        return array_slice($suggestions, 0, $wanted);
    }

    /**
     * Расшифровки, разложенные на части по объёму, каждая — со своей нумерацией.
     *
     * Номера порядковые, а не идентификаторы записей: увидев настоящие, модель
     * принимается складывать из них собственные — та же беда, что и со ссылками
     * в ответах. Нумерация внутри части, потому что часть — это один запрос, и
     * дальше первого десятка номера модель всё равно путает.
     *
     * @param  iterable<TranscriptSegment>  $segments
     * @return list<array<int, TranscriptSegment>>
     */
    private function chunks(iterable $segments): array
    {
        $all = is_array($segments) ? $segments : iterator_to_array($segments);
        $total = 0;

        foreach ($all as $segment) {
            $total += mb_strlen((string) $segment->content);
        }

        if ($total === 0) {
            return [];
        }

        // Части равные, а не «сколько влезло»: у длинной записи иначе последняя
        // выходит огрызком в пару реплик, и вопросов по ней столько же, сколько
        // по полновесной.
        $count = max(1, min(self::MAX_CHUNKS, (int) ceil($total / self::CHUNK_CHARS)));
        $budget = (int) ceil($total / $count);

        $chunks = [];
        $current = [];
        $length = 0;
        $number = 1;

        foreach ($all as $segment) {
            $size = mb_strlen((string) $segment->content);

            if ($current !== [] && $length + $size > $budget && count($chunks) < $count - 1) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
                $number = 1;
            }

            $current[$number++] = $segment;
            $length += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Один запрос к модели по одной части расшифровок.
     *
     * @param  array<int, TranscriptSegment>  $numbered
     * @return list<array<string, mixed>>
     */
    private function askAbout(Lesson $lesson, array $numbered, int $wanted): array
    {
        $message = $this->client->messages->create(
            model: $this->settings->model(),
            // Свой предел, не тот, которым модель отвечает сотруднику: черновик
            // приходит массивом JSON, и обрезанный посередине не разбирается
            // вовсе — автор получает не «сколько влезло», а ноль.
            maxTokens: (int) config('ai.suggestion_max_tokens'),
            system: $this->system(),
            messages: [['role' => 'user', 'content' => $this->userTurn($lesson, $numbered, $wanted)]],
        );

        $raw = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $raw .= $block->text;
            }
        }

        return $this->parse($raw, $numbered);
    }

    private function system(): string
    {
        return <<<'TEXT'
        Ты помогаешь автору курса разметить урок: выписываешь вопросы, на которые этот урок отвечает,
        и ответы на них — строго по переданным фрагментам расшифровок.

        Правила:

        1. Вопрос формулируй так, как его задал бы сотрудник своими словами, а не заголовком раздела.
           «Сколько сохнет второй слой?», а не «Время высыхания».
        2. Ответ бери из фрагмента. Не добавляй ничего от себя и не обобщай: если сказано «не менее
           4 часов при 20 °C», так и пиши, а не «несколько часов».
        3. Ответ должен быть законченным и понятным без урока — его прочтут отдельно от него.
        4. Не выписывай вопросы, ответа на которые во фрагментах нет. Пустой список — нормальный ответ.
        5. Не повторяйся: два вопроса об одном и том же разными словами не нужны.
        6. Для каждого вопроса укажи номер фрагмента, из которого взят ответ. Если ответ собран из
           нескольких — укажи тот, где сказано главное.

        Ответ верни строго как массив JSON, без пояснений и без разметки, в таком виде:

        [{"question": "...", "answer": "...", "fragment": 3}]
        TEXT;
    }

    /**
     * @param  array<int, TranscriptSegment>  $numbered
     */
    private function userTurn(Lesson $lesson, array $numbered, int $wanted): string
    {
        $fragments = [];

        foreach ($numbered as $number => $segment) {
            $fragments[] = sprintf(
                "[фрагмент %d] %s\n%s",
                $number,
                $this->describe($segment),
                $segment->content,
            );
        }

        return sprintf(
            "УРОК: «%s»\n\nРАСШИФРОВКИ:\n\n%s\n\nВыпиши до %d вопросов.",
            $lesson->title,
            implode("\n\n", $fragments),
            $wanted,
        );
    }

    /**
     * Откуда фрагмент.
     *
     * Модели это нужно не ради ссылки — ссылку проставляет приложение, — а
     * чтобы она не выдавала сказанное вслух за написанное в регламенте.
     */
    private function describe(TranscriptSegment $segment): string
    {
        $transcript = $segment->transcript;

        return match ($transcript?->source_kind) {
            AnswerSource::Video => 'из видео урока',
            AnswerSource::Attachment => 'из файла «'.($transcript->attachment?->name ?? 'без имени').'»',
            default => 'из текста урока',
        };
    }

    /**
     * Разбирает ответ модели, отбрасывая всё, чему нельзя верить.
     *
     * Модель просили вернуть JSON, но обёртка в ```json и вводная фраза
     * случаются и при самой ясной инструкции. Номер фрагмента сверяется с
     * выданными: выдуманный означал бы строку с источником, которого ей не
     * показывали.
     *
     * @param  array<int, TranscriptSegment>  $numbered
     * @return list<array<string, mixed>>
     */
    private function parse(string $raw, array $numbered): array
    {
        if (! preg_match('/\[.*]/s', $raw, $match)) {
            return [];
        }

        $decoded = json_decode($match[0], true);

        if (! is_array($decoded)) {
            return [];
        }

        $suggestions = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            $segment = $numbered[(int) ($row['fragment'] ?? 0)] ?? null;

            if ($question === '' || $answer === '' || $segment === null) {
                continue;
            }

            $suggestions[] = [
                'question' => mb_substr($question, 0, 1000),
                'answer' => mb_substr($answer, 0, 10000),
                ...$this->sourceOf($segment),
            ];
        }

        return $suggestions;
    }

    /**
     * Источник строки, выведенный из фрагмента.
     *
     * То, ради чего расшифровки привязаны к единицам содержания: вопрос,
     * взятый из записи, получает и вид источника, и секунду — без единого
     * действия автора.
     *
     * @return array<string, mixed>
     */
    private function sourceOf(TranscriptSegment $segment): array
    {
        $transcript = $segment->transcript;
        $kind = $transcript?->source_kind ?? AnswerSource::Text;

        return [
            'source_kind' => $kind->value,
            'source_attachment_id' => $kind === AnswerSource::Attachment
                ? $transcript?->source_attachment_id
                : null,
            'source_seconds' => $kind === AnswerSource::Video ? $segment->starts_at_seconds : null,
            'source_page' => $kind === AnswerSource::Attachment ? $segment->page : null,
            // Абзац помнит кусок: расшифровка статьи одна на урок, и указать
            // место может только он. У расшифровки поле остаётся ради
            // загруженных вручную.
            'source_block_id' => $kind === AnswerSource::Text
                ? ($segment->source_block_id ?? $transcript?->source_block_id)
                : null,
        ];
    }
}
