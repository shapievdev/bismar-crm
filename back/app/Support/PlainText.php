<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The readable words of a fragment of HTML.
 *
 * `strip_tags` alone is not this: it removes the tag and keeps whatever sat
 * inside it, so a pasted page leaves its entire stylesheet behind as prose.
 * That text then goes into the lesson's searchable content, where it matches
 * questions no one asked and fills the consultant's excerpts with CSS.
 */
final readonly class PlainText
{
    /**
     * Elements whose contents are not prose and must go with the tag.
     *
     * `strip_tags` removes the tag and keeps what is inside it, which for a
     * stylesheet means keeping the entire stylesheet.
     */
    private const NON_PROSE = '/<(script|style|head|noscript)\b[^>]*>.*?<\/\1>/is';

    /** Where a tag implies a break rather than a word boundary. */
    private const BREAKS = '/<\/?(p|div|br|li|tr|h[1-6]|section|article)\b[^>]*>/i';

    /**
     * Конец ячейки таблицы.
     *
     * Без него соседние ячейки слипаются в одно слово: строка заголовков
     * «Позиция | Почему нужна | Как предложить» превращалась в
     * «ПозицияПочему нужнаКак предложить» — нечитаемо для человека и
     * бессмысленно для поиска, потому что таких лемм не существует.
     */
    private const CELLS = '/<\/(td|th)\s*>/i';

    public static function of(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace(self::NON_PROSE, ' ', $html) ?? $html;
        $text = preg_replace(self::CELLS, ' | ', $text) ?? $text;
        $text = preg_replace(self::BREAKS, "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Runs of blank space become one space and runs of blank lines one
        // newline: the shape of the text is worth keeping, its indentation is
        // not.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        // Разделитель последней ячейки строки повисает в конце — убираем его,
        // иначе каждая строка таблицы кончается пустым столбцом.
        $text = preg_replace('/\s*\|\s*$/mu', '', $text) ?? $text;

        return trim($text);
    }
}
