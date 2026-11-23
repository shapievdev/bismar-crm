<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Regulation;
use App\Models\RegulationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class ArticleIntegrityTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    public function test_article_spacing_and_html_are_not_transformed_by_request_middleware(): void
    {
        $article = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Начало '],
                ['type' => 'text', 'text' => 'с выделением', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' '],
                ['type' => 'text', 'text' => ' конец'],
            ]],
            ['type' => 'htmlBlock', 'attrs' => ['html' => '  <pre> text </pre>  ', 'src' => '']],
        ]];
        $response = $this->actingAs($this->author())->postJson(route('lms.documents.store'), [
            'title' => '  Инструкция  ', 'summary' => '', 'status' => 'draft', 'visibility' => 'public',
            'category_id' => RegulationCategory::factory()->create()->id, 'content_json' => $article,
        ])->assertCreated()->assertJsonPath('data.title', 'Инструкция')->assertJsonPath('data.summary', null);

        $saved = Regulation::findOrFail($response->json('data.id'))->content_json;
        $this->assertEquals($article['content'][0]['content'], $saved['content'][0]['content']);
        $this->assertSame($article['content'][1]['attrs']['html'], $saved['content'][1]['attrs']['html']);
        $this->assertSame('', $saved['content'][1]['attrs']['src']);
    }

    public function test_invalid_text_nodes_are_rejected_without_overwriting_existing_article(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $before = $document->content_json;
        foreach ([null, '', 123] as $invalid) {
            $this->actingAs($author)->putJson(route('lms.documents.update', $document), [
                'title' => $document->title, 'status' => 'draft', 'visibility' => 'public',
                'category_id' => $document->category_id,
                'content_json' => ['type' => 'doc', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $invalid]]],
                ]],
            ])->assertUnprocessable()->assertJsonValidationErrors('content_json');
            $this->assertEquals($before, $document->fresh()->content_json);
        }
    }
}
