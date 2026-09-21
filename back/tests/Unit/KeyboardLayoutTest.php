<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Search\KeyboardLayout;
use PHPUnit\Framework\TestCase;

/**
 * Забытая раскладка — сама таблица клавиш.
 *
 * Проверяется то, из-за чего поблажка и заведена: слово, набранное латиницей по
 * русским клавишам, читается тем словом, которое человек набирал. И обратное —
 * что читать второй раскладкой берутся не всякий запрос: смесь алфавитов и
 * одиночная буква остаются как есть.
 */
final class KeyboardLayoutTest extends TestCase
{
    /** То самое, с чего всё началось: «ljrevtyn» — это «документ». */
    public function test_latin_typing_reads_as_the_russian_word(): void
    {
        $this->assertSame(
            ['ljrevtyn', 'документ'],
            KeyboardLayout::readings('ljrevtyn'),
        );
    }

    /**
     * Знаки препинания — тоже клавиши: «б» живёт на запятой, «ж» — на точке с
     * запятой, «х» — на квадратной скобке. Без них перевод терял бы букву.
     */
    public function test_punctuation_keys_carry_letters_too(): void
    {
        $this->assertSame('банк', KeyboardLayout::otherReading(',fyr'));
        $this->assertSame('объём', KeyboardLayout::otherReading('j,]`v'));
        $this->assertSame('журнал', KeyboardLayout::otherReading(';ehyfk'));
    }

    /** И наоборот: латинское название, набранное в русской раскладке. */
    public function test_russian_typing_reads_as_the_latin_word(): void
    {
        $this->assertSame(['ызф', 'spa'], KeyboardLayout::readings('ызф'));
    }

    /**
     * Регистр не теряется: набранное с Caps Lock читается заглавными, а не
     * пропадает из перевода.
     */
    public function test_the_case_of_the_typing_survives(): void
    {
        $this->assertSame('ДОКУМЕНТ', KeyboardLayout::otherReading('LJREVTYN'));
    }

    /** Цифры и пробелы — сами собой: им в другой раскладке соответствия нет. */
    public function test_digits_and_spaces_pass_through(): void
    {
        $this->assertSame('приказ 12', KeyboardLayout::otherReading('ghbrfp 12'));
    }

    /**
     * Смесь алфавитов — это человек, который раскладку как раз переключил:
     * догадываться за него незачем.
     */
    public function test_mixed_alphabets_are_left_alone(): void
    {
        $this->assertNull(KeyboardLayout::otherReading('краска pdf'));
        $this->assertSame(['краска pdf'], KeyboardLayout::readings('краска pdf'));
    }

    /**
     * Одна буква в другой раскладке — это другая одна буква, и найдёт она всё
     * что угодно.
     */
    public function test_a_single_letter_is_not_reread(): void
    {
        $this->assertNull(KeyboardLayout::otherReading('j'));
        $this->assertSame(['j'], KeyboardLayout::readings('j'));
    }

    /** Ни букв, ни смысла перечитывать: номер остаётся номером. */
    public function test_a_query_without_letters_stays_as_it_is(): void
    {
        $this->assertNull(KeyboardLayout::otherReading('2026'));
    }

    public function test_an_empty_query_has_nothing_to_read(): void
    {
        $this->assertSame([], KeyboardLayout::readings('  '));
        $this->assertSame([], KeyboardLayout::readings(null));
    }

    /**
     * Кириллическое чтение — только для латиницы: консультанту нужно чтение
     * «вместо», и превращать русский вопрос в латинскую бессмыслицу нельзя.
     */
    public function test_only_latin_typing_has_a_cyrillic_reading(): void
    {
        $this->assertSame('документ', KeyboardLayout::cyrillicReading('ljrevtyn'));
        $this->assertNull(KeyboardLayout::cyrillicReading('документ'));
    }
}
