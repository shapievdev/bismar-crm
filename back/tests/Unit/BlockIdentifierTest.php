<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Lms\BlockIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * Имена блоков — то, чем строка таблицы указывает на абзац.
 *
 * Всё здесь про одно свойство: имя, однажды выданное блоку, обязано его
 * пережить. Ссылка на абзац живёт годами, а урок за это время правят десятки
 * раз, и каждая правка не должна её ломать.
 */
final class BlockIdentifierTest extends TestCase
{
    public function test_every_top_level_block_gets_a_name(): void
    {
        $document = $this->documentOf(
            ['type' => 'paragraph'],
            ['type' => 'heading'],
        );

        $result = (new BlockIdentifier)->assign($document);

        $names = array_column(array_column($result['content'], 'attrs'), BlockIdentifier::ATTRIBUTE);

        $this->assertCount(2, array_filter($names));
        $this->assertNotSame($names[0], $names[1], 'Двум блокам выдали одно имя.');
    }

    /**
     * Главное свойство: правка соседнего абзаца не трогает этот.
     *
     * Иначе любая правка урока обнуляла бы все ссылки на него разом — молча, и
     * узнали бы об этом только когда читатель нажмёт на источник.
     */
    public function test_an_existing_name_is_never_reissued(): void
    {
        $document = $this->documentOf(
            ['type' => 'paragraph', 'attrs' => [BlockIdentifier::ATTRIBUTE => 'уже-названный']],
            ['type' => 'paragraph'],
        );

        $result = (new BlockIdentifier)->assign($document);

        $this->assertSame('уже-названный', $result['content'][0]['attrs'][BlockIdentifier::ATTRIBUTE]);
    }

    public function test_a_second_pass_changes_nothing(): void
    {
        $identifier = new BlockIdentifier;

        $once = $identifier->assign($this->documentOf(['type' => 'paragraph'], ['type' => 'paragraph']));
        $twice = $identifier->assign($once);

        $this->assertSame($once, $twice);
    }

    /**
     * Скопированный блок приносит с собой чужое имя. Два блока под одним именем
     * — та же сломанная ссылка, только молчаливая: она приведёт читателя к
     * одному из двух наугад.
     */
    public function test_a_duplicated_name_is_replaced(): void
    {
        $document = $this->documentOf(
            ['type' => 'paragraph', 'attrs' => [BlockIdentifier::ATTRIBUTE => 'общее']],
            ['type' => 'paragraph', 'attrs' => [BlockIdentifier::ATTRIBUTE => 'общее']],
        );

        $result = (new BlockIdentifier)->assign($document);

        $names = array_column(array_column($result['content'], 'attrs'), BlockIdentifier::ATTRIBUTE);

        $this->assertNotSame($names[0], $names[1]);
        $this->assertContains('общее', $names, 'Первому блоку сменили имя, хотя занимал его он.');
    }

    public function test_names_are_read_back_from_the_whole_document(): void
    {
        $document = $this->documentOf(
            ['type' => 'paragraph', 'attrs' => [BlockIdentifier::ATTRIBUTE => 'первый']],
            [
                'type' => 'table',
                'attrs' => [BlockIdentifier::ATTRIBUTE => 'второй'],
                'content' => [['type' => 'tableRow', 'attrs' => [BlockIdentifier::ATTRIBUTE => 'вложенный']]],
            ],
        );

        $this->assertSame(
            ['первый', 'второй', 'вложенный'],
            (new BlockIdentifier)->identifiers($document),
        );
    }

    public function test_an_empty_document_is_left_alone(): void
    {
        $identifier = new BlockIdentifier;

        $this->assertNull($identifier->assign(null));
        $this->assertSame([], $identifier->identifiers(null));
    }

    /**
     * @param  array<string, mixed>  ...$blocks
     * @return array<string, mixed>
     */
    private function documentOf(array ...$blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }
}
