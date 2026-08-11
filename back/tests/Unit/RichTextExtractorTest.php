<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Lms\RichTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * What a lesson is searchable by.
 *
 * The result of this is stored as the lesson's plain text, so anything that
 * leaks in here is matched by full-text search and quoted back by the
 * consultant as though a person had written it.
 */
final class RichTextExtractorTest extends TestCase
{
    private RichTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new RichTextExtractor;
    }

    public function test_it_reads_the_words_out_of_a_document(): void
    {
        $text = $this->extractor->toPlainText([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Возражение «дорого».']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Выслушайте клиента.']]],
            ],
        ]);

        $this->assertSame('Возражение «дорого». Выслушайте клиента.', $text);
    }

    /**
     * A pasted page brings its stylesheet with it, and `strip_tags` keeps what
     * is inside the tag it removes. Left alone, every lesson pasted this way
     * became searchable by "sans-serif" and fed the consultant CSS instead of
     * the two sentences that answer the question.
     */
    public function test_it_drops_the_stylesheet_of_a_pasted_page(): void
    {
        $text = $this->extractor->toPlainText([
            'type' => 'doc',
            'content' => [[
                'type' => 'htmlBlock',
                'attrs' => ['html' => <<<'HTML'
                    <style>:root{ --ink:#1F2A33; --body:"IBM Plex Sans",sans-serif; }</style>
                    <script>window.track('open')</script>
                    <p>Скидка согласуется с руководителем.</p>
                HTML],
            ]],
        ]);

        $this->assertSame('Скидка согласуется с руководителем.', $text);
    }

    /**
     * Таблицы в базе знаний несут самое полезное — «позиция, зачем, как
     * предложить». Без разделителя ячеек строка склеивалась в «ПозицияПочему
     * нужнаКак предложить»: таких слов нет ни в одном словаре, и поиск по ним
     * не находит ничего.
     */
    public function test_table_cells_do_not_glue_together(): void
    {
        $text = $this->extractor->toPlainText([
            'type' => 'doc',
            'content' => [[
                'type' => 'htmlBlock',
                'attrs' => ['html' => <<<'HTML'
                    <table>
                      <tr><th>Позиция</th><th>Почему нужна</th></tr>
                      <tr><td>Грунт</td><td>Снижает расход</td></tr>
                    </table>
                HTML],
            ]],
        ]);

        $this->assertStringContainsString('Позиция | Почему нужна', $text);
        $this->assertStringContainsString('Грунт | Снижает расход', $text);
        $this->assertStringNotContainsString('ПозицияПочему', $text);
    }

    public function test_it_decodes_entities_rather_than_indexing_them(): void
    {
        $text = $this->extractor->toPlainText([
            'type' => 'doc',
            'content' => [[
                'type' => 'htmlBlock',
                'attrs' => ['html' => '<p>Цена &laquo;под ключ&raquo; &mdash; 10&nbsp;000&nbsp;₽</p>'],
            ]],
        ]);

        $this->assertSame('Цена «под ключ» — 10 000 ₽', $text);
    }

    public function test_it_survives_a_document_that_is_not_a_document(): void
    {
        $this->assertSame('', $this->extractor->toPlainText(null));
        $this->assertSame('', $this->extractor->toPlainText([]));
        $this->assertSame('', $this->extractor->toPlainText(['type' => 'doc', 'content' => 'не массив']));
    }
}
