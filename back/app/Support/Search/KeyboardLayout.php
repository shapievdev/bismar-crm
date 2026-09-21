<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * Забытая раскладка: «ljrevtyn» — это «документ».
 *
 * Сотрудник печатает, не глядя на строку поиска, и половину запросов набирает
 * латиницей по русским клавишам. Раньше такой запрос находил ровно ничего — и
 * выглядело это так, будто в базе ничего нет, а не так, будто нажата не та
 * клавиша. Поэтому поиск читает набранное двумя способами и ищет по обоим.
 *
 * Переводится не текст, а нажатия: буквы ЙЦУКЕН стоят на клавишах QWERTY, и «б»
 * живёт на запятой, а «ж» — на точке с запятой. Без знаков препинания перевод
 * терял бы каждое слово с «б», «ю», «ж», «э», «х» и «ъ» — то есть «объём»,
 * «оплата» мимо, а «банк» превращался бы в «анк».
 *
 * Читается запрос целиком, а не по словам, и только когда в нём нет смеси двух
 * алфавитов: смесь — это человек, который раскладку как раз переключил, и
 * догадываться за него незачем.
 */
final readonly class KeyboardLayout
{
    /**
     * Клавиши в латинской раскладке и те же клавиши в русской.
     *
     * Две строки одной длины, символ против символа: пары собираются из них, и
     * ошибка в одной сразу видна по сдвигу остальных. Ряды идут как на
     * клавиатуре — верхний, средний, нижний, — сперва без Shift, потом с ним.
     */
    private const QWERTY = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,./`QWERTYUIOP{}ASDFGHJKL:"ZXCVBNM<>?~';

    private const JCUKEN = 'йцукенгшщзхъфывапролджэячсмитьбю.ёЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ,Ё';

    /**
     * Короче двух букв не перечитываем.
     *
     * Одна буква в другой раскладке — это другая одна буква, и найдёт она всё
     * что угодно: «j» стало бы «о» и подняло бы половину базы.
     */
    private const MIN_LETTERS = 2;

    /**
     * Как запрос стоит прочесть — своими словами и второй раскладкой.
     *
     * Первым всегда идёт то, что набрано: набрали правильно — им и ищем, а
     * второе чтение лишь добавляется рядом. Второго может не быть вовсе.
     *
     * @return list<string>
     */
    public static function readings(?string $term): array
    {
        $term = trim((string) $term);

        if ($term === '') {
            return [];
        }

        $other = self::otherReading($term);

        return $other === null ? [$term] : [$term, $other];
    }

    /**
     * То же, прочитанное второй раскладкой, — или null, если читать незачем.
     *
     * Незачем в трёх случаях: букв нет вовсе, алфавиты смешаны (раскладку
     * переключали осознанно) или перевод ничего не изменил.
     */
    public static function otherReading(string $term): ?string
    {
        $latin = preg_match_all('/[a-zA-Z]/', $term);
        $cyrillic = preg_match_all('/[\x{0400}-\x{04FF}]/u', $term);

        if ($latin > 0 && $cyrillic > 0) {
            return null;
        }

        $translated = match (true) {
            $latin >= self::MIN_LETTERS => self::translate($term, self::QWERTY, self::JCUKEN),
            $cyrillic >= self::MIN_LETTERS => self::translate($term, self::JCUKEN, self::QWERTY),
            default => null,
        };

        return $translated === $term ? null : $translated;
    }

    /**
     * Кириллическое чтение латиницы — для тех, кому нужно не «или», а «вместо».
     *
     * Поиск по списку может искать по двум чтениям разом, а консультанту нужно
     * выбрать одно: по вопросу уходит вектор, и он же уходит модели. Вернуть
     * чтение — половина дела; решить, то ли оно, может лишь тот, у кого под
     * рукой сам материал (см. KnowledgeBase::retyped).
     */
    public static function cyrillicReading(string $term): ?string
    {
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $term) === 1) {
            return null;
        }

        return self::otherReading($term);
    }

    private static function translate(string $term, string $from, string $to): string
    {
        return strtr($term, array_combine(
            mb_str_split($from),
            mb_str_split($to),
        ));
    }
}
