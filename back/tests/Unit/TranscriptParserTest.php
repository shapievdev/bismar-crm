<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Lms\PassageSplitter;
use App\Support\Lms\TranscriptParser;
use PHPUnit\Framework\TestCase;

/**
 * Расшифровка приходит в том виде, в каком её отдал сервис распознавания, — и
 * дальше по коду обязана быть одним и тем же.
 *
 * Время здесь важнее текста: по нему ссылка на источник перематывает запись, и
 * ошибка в разборе уводит читателя не туда молча.
 */
final class TranscriptParserTest extends TestCase
{
    private TranscriptParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TranscriptParser(new PassageSplitter);
    }

    public function test_srt_cues_carry_their_start_time(): void
    {
        $cues = $this->parser->parse(<<<'SRT'
        1
        00:00:04,120 --> 00:00:07,000
        Сегодня разберём покраску стен.

        2
        00:12:35,000 --> 00:12:39,500
        Второй слой сохнет не менее
        четырёх часов при двадцати градусах.
        SRT);

        $this->assertCount(1, $cues, 'Мелкие реплики должны склеиться в один кусок.');
        $this->assertSame(4, $cues[0]->startsAt, 'Временем куска должно быть время первой реплики.');
        $this->assertStringContainsString('Сегодня разберём покраску стен.', $cues[0]->text);
        // Перенос длинной фразы на вторую строку кадра — не конец фразы.
        $this->assertStringContainsString('четырёх часов при двадцати градусах.', $cues[0]->text);
    }

    /**
     * Номер следующей реплики стоит вплотную к тексту этой. Прочитанный как
     * текст, он становится фразой из одних цифр.
     */
    public function test_a_cue_number_never_becomes_text(): void
    {
        $cues = $this->parser->parse(<<<'SRT'
        1
        00:00:04,120 --> 00:00:07,000
        Первая фраза.

        2
        00:00:08,000 --> 00:00:10,000
        Вторая фраза.
        SRT);

        $this->assertSame('Первая фраза. Вторая фраза.', $cues[0]->text);
    }

    public function test_vtt_is_recognised_and_stripped_of_markup(): void
    {
        $cues = $this->parser->parse(<<<'VTT'
        WEBVTT

        00:12:35.000 --> 00:12:39.500 line:80%
        <v Лектор>Второй слой сохнет <i>не менее</i> четырёх часов.
        VTT);

        $this->assertCount(1, $cues);
        $this->assertSame(755, $cues[0]->startsAt);
        $this->assertSame('Второй слой сохнет не менее четырёх часов.', $cues[0]->text);
    }

    public function test_plain_lines_beginning_with_a_time_are_read(): void
    {
        $cues = $this->parser->parse(<<<'TEXT'
        00:12:35 Второй слой сохнет не менее четырёх часов.
        а если в помещении холоднее, то дольше.
        [13:02] Углы красят кистью.
        TEXT);

        $this->assertCount(1, $cues);
        $this->assertSame(755, $cues[0]->startsAt);
        // Строка без времени — продолжение предыдущей реплики, а не новая.
        $this->assertStringContainsString('а если в помещении холоднее', $cues[0]->text);
        $this->assertStringContainsString('Углы красят кистью.', $cues[0]->text);
    }

    /** Двоеточие в «мм:сс» без часов — тоже время, а не текст. */
    public function test_a_two_part_timecode_is_minutes_and_seconds(): void
    {
        $cues = $this->parser->parse('12:35 Второй слой сохнет четыре часа.');

        $this->assertSame(755, $cues[0]->startsAt);
    }

    public function test_plain_text_has_no_time_at_all(): void
    {
        $cues = $this->parser->parse('Второй слой сохнет не менее четырёх часов при двадцати градусах.');

        $this->assertCount(1, $cues);
        $this->assertNull($cues[0]->startsAt);
        $this->assertSame('Второй слой сохнет не менее четырёх часов при двадцати градусах.', $cues[0]->text);
    }

    /**
     * Расшифровки документов размечают листы, и это единственная зацепка, по
     * которой ссылка приведёт читателя на нужную страницу.
     */
    public function test_a_page_marker_is_carried_into_the_segments(): void
    {
        $cues = $this->parser->parse(<<<'TEXT'
        --- Страница 1 ---

        Общие положения о подборе краски.

        --- Страница 4 ---

        Влажные помещения требуют краски с маркировкой «для ванных».
        TEXT);

        $this->assertSame(1, $cues[0]->page);
        $this->assertSame(4, $cues[count($cues) - 1]->page);
    }

    /** Спецификатор кодировки невидим, а распознаванию мешает. */
    public function test_a_byte_order_mark_does_not_hide_the_format(): void
    {
        $this->assertSame(
            TranscriptParser::FORMAT_VTT,
            $this->parser->format("\u{FEFF}WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nТекст."),
        );
    }

    public function test_windows_line_endings_are_understood(): void
    {
        $cues = $this->parser->parse("1\r\n00:12:35,000 --> 00:12:39,500\r\nВторой слой сохнет.\r\n");

        $this->assertCount(1, $cues);
        $this->assertSame(755, $cues[0]->startsAt);
        $this->assertSame('Второй слой сохнет.', $cues[0]->text);
    }

    public function test_an_empty_transcript_yields_nothing(): void
    {
        $this->assertSame([], $this->parser->parse("   \n\n  "));
    }

    /**
     * Длинная запись должна распасться на куски: целиком она не поместится ни в
     * промпт, ни в осмысленное совпадение с вопросом.
     */
    public function test_a_long_transcript_is_split_into_several_segments(): void
    {
        $lines = [];

        for ($minute = 0; $minute < 40; $minute++) {
            $lines[] = sprintf('00:%02d:00 Довольно длинная реплика про подготовку основания и грунтование стен.', $minute);
        }

        $cues = $this->parser->parse(implode("\n", $lines));

        $this->assertGreaterThan(3, count($cues));
        $this->assertSame(0, $cues[0]->startsAt);

        foreach ($cues as $cue) {
            $this->assertNotNull($cue->startsAt, 'Каждый кусок обязан знать, с какой секунды он начинается.');
        }
    }
}
