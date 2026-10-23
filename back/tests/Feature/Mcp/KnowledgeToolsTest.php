<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\Permission;
use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\Knowledge\ReadKnowledge;
use App\Mcp\Tools\Knowledge\SearchKnowledge;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * База знаний через коннектор: найти и прочитать.
 *
 * Главное здесь — что отбор по правам не свой: поиск идёт тем же классом, что
 * в шапке приложения, а чтение спрашивает ту же политику, что страница. Поэтому
 * проверяется не «работает ли выдача», а что закрытое остаётся закрытым.
 */
final class KnowledgeToolsTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    public function test_a_reader_finds_a_published_document_and_reads_it(): void
    {
        $document = Regulation::factory()->published()->create([
            'title' => 'Правила возврата товара',
            'content_json' => $this->article('Возврат принимается в течение двух недель.'),
        ]);

        $reader = $this->learner();

        Bismar::actingAs($reader)
            ->tool(SearchKnowledge::class, ['query' => 'возврат'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('sections', fn (Collection $sections): bool => $sections
                    ->contains(fn (array $section): bool => $section['kind'] === 'document'
                        && $section['items'][0]['id'] === $document->getKey()))
                ->etc());

        Bismar::actingAs($reader)
            ->tool(ReadKnowledge::class, ['kind' => 'document', 'id' => $document->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Правила возврата товара')
                // Статья приходит текстом, а не деревом блоков редактора.
                ->where('article', 'Возврат принимается в течение двух недель.')
                ->etc());
    }

    /**
     * Раздел, который человеку не открыт, не даёт ни находок, ни чтения.
     *
     * Права у документов и справочников свои (2026-09-11), и это ровно тот
     * случай, ради которого инструмент не пишет своего запроса: условие живёт в
     * Everywhere и в политике материала.
     */
    public function test_a_section_that_is_closed_gives_neither_hits_nor_the_article(): void
    {
        $handbook = Regulation::factory()->handbook()->published()->create([
            'title' => 'Справочник по гарантии',
        ]);

        // Открыты только документы; справочники — нет.
        $reader = $this->userWith(Permission::ViewDocuments);

        Bismar::actingAs($reader)
            ->tool(SearchKnowledge::class, ['query' => 'гарант'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('found', 0)->etc());

        Bismar::actingAs($reader)
            ->tool(ReadKnowledge::class, ['kind' => 'handbook', 'id' => $handbook->getKey()])
            ->assertHasErrors();
    }

    /** Черновик читает тот, кто его правит, — остальным его ещё не показывают. */
    public function test_a_draft_is_not_read_by_a_learner(): void
    {
        $draft = Regulation::factory()->create(['title' => 'Черновик про доставку']);

        Bismar::actingAs($this->learner())
            ->tool(ReadKnowledge::class, ['kind' => 'document', 'id' => $draft->getKey()])
            ->assertHasErrors();

        Bismar::actingAs($this->editor())
            ->tool(ReadKnowledge::class, ['kind' => 'document', 'id' => $draft->getKey()])
            ->assertOk()
            ->assertHasNoErrors();
    }

    /** Вид в адресе значим: документ по адресу справочника не открывается. */
    public function test_a_document_is_not_reachable_as_a_handbook(): void
    {
        $document = Regulation::factory()->published()->create();

        Bismar::actingAs($this->learner())
            ->tool(ReadKnowledge::class, ['kind' => 'handbook', 'id' => $document->getKey()])
            ->assertHasErrors();
    }

    public function test_an_unknown_number_is_refused_in_words(): void
    {
        Bismar::actingAs($this->learner())
            ->tool(ReadKnowledge::class, ['kind' => 'document', 'id' => 4242])
            ->assertHasErrors(['Документа №4242 нет.']);
    }

    /**
     * Поиск открыт всякому, кто вошёл: новости читает вся компания, и отдельного
     * права на поиск в приложении нет.
     */
    public function test_searching_is_offered_to_everybody(): void
    {
        Bismar::actingAs(User::factory()->create())
            ->tools()
            ->assertRegistered([SearchKnowledge::class, ReadKnowledge::class]);
    }

    /**
     * Содержимое редактора, каким его хранит база.
     *
     * @return array<string, mixed>
     */
    private function article(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }
}
