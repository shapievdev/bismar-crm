<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Lms\PassageSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Как урок делится на куски, которые ищет консультант.
 *
 * Граница проходит по абзацу или по предложению: обрывок с середины фразы
 * модель цитирует так же уверенно, как целое утверждение.
 */
final class PassageSplitterTest extends TestCase
{
    private PassageSplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->splitter = new PassageSplitter;
    }

    public function test_a_short_lesson_stays_one_passage(): void
    {
        $passages = $this->splitter->split("Первый абзац.\n\nВторой абзац.");

        $this->assertSame(["Первый абзац.\n\nВторой абзац."], $passages);
    }

    public function test_it_returns_nothing_for_an_empty_lesson(): void
    {
        $this->assertSame([], $this->splitter->split(null));
        $this->assertSame([], $this->splitter->split("   \n\n  "));
    }

    /**
     * Ради этого всё и делалось: длинный урок должен отдавать поиску не только
     * своё начало.
     */
    public function test_a_long_lesson_is_cut_into_several_passages(): void
    {
        $paragraph = str_repeat('Обычное предложение о краске. ', 12);
        $passages = $this->splitter->split(implode("\n\n", array_fill(0, 6, trim($paragraph))));

        $this->assertGreaterThan(1, count($passages));

        foreach ($passages as $passage) {
            $this->assertLessThanOrEqual(1600, mb_strlen($passage));
        }
    }

    public function test_a_paragraph_too_long_to_keep_is_cut_between_sentences(): void
    {
        $sentence = 'Грунт выравнивает впитывание основания и снижает расход краски на стене. ';
        $passages = $this->splitter->split(trim(str_repeat($sentence, 40)));

        $this->assertGreaterThan(1, count($passages));

        foreach ($passages as $passage) {
            // Каждый кусок начинается с начала предложения и кончается его
            // концом — иначе смысл фразы теряется вместе с её половиной.
            $this->assertMatchesRegularExpression('/^[А-ЯЁA-Z]/u', $passage);
            $this->assertStringEndsWith('.', $passage);
        }
    }

    public function test_the_whole_text_survives_the_split(): void
    {
        $text = "Первый абзац про грунт.\n\nВторой абзац про краску.\n\nТретий абзац про валик.";

        $joined = implode(' ', $this->splitter->split($text));

        foreach (['грунт', 'краску', 'валик'] as $word) {
            $this->assertStringContainsString($word, $joined);
        }
    }
}
