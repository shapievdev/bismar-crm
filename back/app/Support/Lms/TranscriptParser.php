<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Приводит расшифровку любого привычного вида к репликам со временем.
 *
 * Сервисы распознавания речи отдают .srt или .vtt, человек приносит текст с
 * таймкодами в начале строки, а расшифровка документа времени не имеет вовсе.
 * Все четыре случая разбираются здесь, чтобы дальше по коду расшифровка была
 * одним и тем же — списком реплик.
 *
 * Реплики субтитров нарочно мелкие: кадр держит одну-две строки, и по секунде
 * с половиной на каждую. Искать по ним поштучно бессмысленно — в такой кусок не
 * помещается законченная мысль, и совпадение с вопросом выпадает случайной
 * фразе. Поэтому реплики склеиваются в куски осмысленного размера, а временем
 * куска остаётся время первой в нём: именно туда надо перемотать, чтобы
 * услышать ответ с начала.
 */
final readonly class TranscriptParser
{
    /** Распознанный вид расшифровки — сохраняется рядом с ней ради автора. */
    public const FORMAT_SRT = 'srt';

    public const FORMAT_VTT = 'vtt';

    public const FORMAT_TIMED = 'timed';

    public const FORMAT_PLAIN = 'plain';

    /**
     * Целевой размер куска.
     *
     * Тот же, что у PassageSplitter, и по той же причине: столько, чтобы мысль
     * поместилась вместе с оговорками, и достаточно мало, чтобы в промпт
     * поместился десяток таких из разных мест.
     */
    private const TARGET = 900;

    /** Время реплики: `00:12:35,120`, `12:35.120`, `12:35`. */
    private const TIMESTAMP = '(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?';

    public function __construct(private PassageSplitter $splitter) {}

    /** Вид расшифровки — по её содержимому, а не по расширению файла. */
    public function format(string $raw): string
    {
        $text = trim($raw);

        if (str_starts_with($text, 'WEBVTT')) {
            return self::FORMAT_VTT;
        }

        if (preg_match('/^\s*\d+\s*\R\s*'.self::TIMESTAMP.'\s*-->/m', $text) === 1) {
            return self::FORMAT_SRT;
        }

        if (preg_match('/'.self::TIMESTAMP.'\s*-->/', $text) === 1) {
            return self::FORMAT_VTT;
        }

        if (preg_match('/^\s*\[?'.self::TIMESTAMP.']?[\s\-—:]/m', $text) === 1) {
            return self::FORMAT_TIMED;
        }

        return self::FORMAT_PLAIN;
    }

    /**
     * Расшифровка как список кусков, готовых к поиску.
     *
     * @return list<TranscriptCue>
     */
    public function parse(string $raw): array
    {
        $text = $this->normalise($raw);

        if (trim($text) === '') {
            return [];
        }

        $cues = match ($this->format($text)) {
            self::FORMAT_SRT, self::FORMAT_VTT => $this->fromSubtitles($text),
            self::FORMAT_TIMED => $this->fromTimedLines($text),
            default => $this->fromPlainText($text),
        };

        return $this->merged($cues);
    }

    /**
     * Переводы строк к одному виду и прочь спецификатор кодировки.
     *
     * BOM в начале файла ломает и распознавание WEBVTT, и разбор первой
     * реплики: он невидим, а `str_starts_with` о нём знает.
     */
    private function normalise(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        return ltrim($text, "\u{FEFF}");
    }

    /**
     * Субтитры: блоки «время --> время» с текстом под ними.
     *
     * Номера реплик, координаты позиционирования и заголовок WEBVTT
     * отбрасываются — это разметка кадра, а не сказанные слова.
     *
     * @return list<TranscriptCue>
     */
    private function fromSubtitles(string $text): array
    {
        // Начало реплики, конец — мимо, остаток строки (координаты кадра) —
        // мимо, дальше текст до следующей реплики. Номер следующей поглощает
        // просмотр вперёд: иначе он оставался бы в тексте этой отдельной
        // «фразой» из одних цифр.
        $pattern = '/^'.self::TIMESTAMP.'\s*-->\s*'.self::TIMESTAMP.'[^\n]*\n'
            .'(.*?)(?=\n*(?:\d+\n)?'.self::TIMESTAMP.'\s*-->|\z)/ms';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $cues = [];

        foreach ($matches as $match) {
            $body = $this->cleanSubtitleBody($match[9] ?? '');

            if ($body !== '') {
                $cues[] = new TranscriptCue($body, $this->toSeconds($match[1], $match[2], $match[3]));
            }
        }

        return $cues;
    }

    /** Текст реплики без разметки: <v Docent> и <i> — оформление, не слова. */
    private function cleanSubtitleBody(string $body): string
    {
        $body = (string) preg_replace('/<[^>]*>/', '', $body);

        $lines = array_map(trim(...), explode("\n", trim($body)));

        return trim(implode(' ', array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * Строки, начинающиеся со времени: `00:12:35 Второй слой сохнет…`.
     *
     * @return list<TranscriptCue>
     */
    private function fromTimedLines(string $text): array
    {
        $cues = [];
        $pending = null;
        $buffer = [];

        foreach (explode("\n", $text) as $line) {
            $matched = preg_match('/^\s*\[?'.self::TIMESTAMP.']?[\s\-—:]+(.*)$/u', $line, $match) === 1;

            if (! $matched) {
                // Продолжение предыдущей реплики: расшифровки переносят длинную
                // фразу на следующую строку без повторения времени.
                $buffer[] = trim($line);

                continue;
            }

            if ($buffer !== []) {
                $cues[] = new TranscriptCue(trim(implode(' ', $buffer)), $pending);
            }

            $pending = $this->toSeconds($match[1], $match[2], $match[3]);
            $buffer = [trim($match[5])];
        }

        if ($buffer !== []) {
            $body = trim(implode(' ', $buffer));

            if ($body !== '') {
                $cues[] = new TranscriptCue($body, $pending);
            }
        }

        return array_values(array_filter($cues, static fn (TranscriptCue $cue): bool => $cue->text !== ''));
    }

    /**
     * Обычный текст: расшифровка документа или заметка от руки.
     *
     * Режется тем же, чем режется статья, — границы абзацев здесь такие же
     * осмысленные, как и там.
     *
     * @return list<TranscriptCue>
     */
    private function fromPlainText(string $text): array
    {
        $cues = [];

        // Сперва по листам, потом по абзацам. Наоборот не выходит: абзацы
        // короткого документа набираются в один кусок, и метка второго листа
        // оказывается внутри куска, помеченного первым.
        foreach ($this->pages($text) as [$page, $body]) {
            foreach ($this->splitter->split($body) as $piece) {
                $cues[] = new TranscriptCue($piece, null, $page);
            }
        }

        return $cues;
    }

    /**
     * Текст, разложенный по листам документа.
     *
     * Сервисы распознавания ставят «--- Страница 4 ---» между листами, и это
     * единственная зацепка, по которой ссылка приведёт читателя на нужный лист.
     * Текст до первой метки листа не имеет — так бывает у титульной части.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private function pages(string $text): array
    {
        $marker = '/^[\s\-—=*_#]*(?:страниц[аы]|стр\.?|page)\s*(\d{1,4})[\s\-—=*_#.]*$/imu';

        $parts = preg_split($marker, $text, flags: PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) === 1) {
            return [[null, $text]];
        }

        // Первый кусок — до первой метки, дальше парами «номер, текст».
        $pages = trim($parts[0]) === '' ? [] : [[null, $parts[0]]];

        for ($index = 1; $index + 1 < count($parts) + 1; $index += 2) {
            $body = $parts[$index + 1] ?? '';

            if (trim($body) !== '') {
                $pages[] = [(int) $parts[$index], $body];
            }
        }

        return $pages;
    }

    /**
     * Склеивает мелкие реплики в куски осмысленного размера.
     *
     * Временем куска остаётся время первой реплики в нём: перемотать надо туда,
     * где мысль начинается, а не туда, где она случайно попала в кадр.
     *
     * Через границу листа не склеивает: кусок, начатый на первой странице и
     * дописанный четвёртой, пометится первой — и ссылка отправит читателя не
     * на тот лист. Со временем такой беды нет, там начало и есть ответ.
     *
     * @param  list<TranscriptCue>  $cues
     * @return list<TranscriptCue>
     */
    private function merged(array $cues): array
    {
        $merged = [];
        $buffer = [];
        $startsAt = null;
        $page = null;

        foreach ($cues as $cue) {
            if ($buffer !== [] && $cue->page !== $page) {
                $merged[] = new TranscriptCue(implode(' ', $buffer), $startsAt, $page);
                $buffer = [];
            }

            if ($buffer === []) {
                $startsAt = $cue->startsAt;
                $page = $cue->page;
            }

            $buffer[] = $cue->text;

            if (mb_strlen(implode(' ', $buffer)) < self::TARGET) {
                continue;
            }

            $merged[] = new TranscriptCue(implode(' ', $buffer), $startsAt, $page);
            $buffer = [];
        }

        if ($buffer !== []) {
            $merged[] = new TranscriptCue(implode(' ', $buffer), $startsAt, $page);
        }

        return $merged;
    }

    private function toSeconds(string $hours, string $minutes, string $seconds): int
    {
        return ((int) $hours) * 3600 + ((int) $minutes) * 60 + (int) $seconds;
    }
}
