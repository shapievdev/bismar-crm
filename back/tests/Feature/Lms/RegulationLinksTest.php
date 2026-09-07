<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * «Рядом по теме» — соседние документы, названные руками.
 *
 * Проверяется главным образом то, что из кода не следует само собой: связь
 * взаимна, и она не выдаёт читателю ни черновик, ни чужое закрытое правило.
 */
final class RegulationLinksTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_linking_a_document_shows_the_block_on_both_pages(): void
    {
        $author = $this->author();

        $about = Regulation::factory()->published()->create(['author_id' => $author->getKey(), 'title' => 'Клиент требует скидку']);
        $other = Regulation::factory()->published()->create(['title' => 'Пришёл брак или пересорт']);

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $about), ['documents' => [$other->getKey()]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Пришёл брак или пересорт');

        $reader = $this->learner();

        $this->actingAs($reader)
            ->getJson(route('lms.documents.show', $about))
            ->assertOk()
            ->assertJsonPath('data.related.0.slug', $other->slug);

        // Само, без второй правки: связал один раз — блок появился у обоих.
        $this->actingAs($reader)
            ->getJson(route('lms.documents.show', $other))
            ->assertOk()
            ->assertJsonPath('data.related.0.slug', $about->slug);
    }

    public function test_unlinking_removes_the_block_on_both_pages(): void
    {
        $author = $this->author();

        $about = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $other = Regulation::factory()->published()->create();

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $about), ['documents' => [$other->getKey()]])
            ->assertOk();

        // Снимают связь со стороны соседа — пропасть она должна с обеих.
        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $other), ['documents' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('regulation_links', 0);

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $about))
            ->assertOk()
            ->assertJsonCount(0, 'data.related');
    }

    public function test_a_document_cannot_stand_next_to_itself(): void
    {
        $author = $this->author();
        $regulation = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $regulation), [
                'documents' => [$regulation->getKey()],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('regulation_links', 0);
    }

    /**
     * Черновик редактор рядом поставить может — дописать его недолго, — но
     * читателю он не показывается: ссылка вела бы в отказ.
     */
    public function test_a_draft_neighbour_is_shown_to_editors_and_hidden_from_readers(): void
    {
        $author = $this->author();

        $published = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $draft = Regulation::factory()->create(['author_id' => $author->getKey()]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $published), ['documents' => [$draft->getKey()]])
            ->assertOk();

        $this->actingAs($author)
            ->getJson(route('lms.documents.show', $published))
            ->assertOk()
            ->assertJsonCount(1, 'data.related')
            ->assertJsonPath('data.related.0.is_published', false);

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $published))
            ->assertOk()
            ->assertJsonCount(0, 'data.related');
    }

    /**
     * Закрытое правило выдаёт себя одним заголовком не хуже, чем страницей.
     */
    public function test_a_closed_neighbour_is_hidden_from_a_reader_who_was_not_let_in(): void
    {
        $author = $this->author();

        $published = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $closed = Regulation::factory()->published()->closed()->create([
            'author_id' => $author->getKey(),
            'title' => 'Кому и какую скидку можно дать',
        ]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $published), ['documents' => [$closed->getKey()]])
            ->assertOk();

        $outsider = $this->learner();

        $this->actingAs($outsider)
            ->getJson(route('lms.documents.show', $published))
            ->assertOk()
            ->assertJsonCount(0, 'data.related');

        // Допущенному — виден: закрытость решает, кому документ открыт, а не
        // то, откуда на него пришли.
        $closed->members()->attach($outsider->getKey());

        $this->actingAs($outsider)
            ->getJson(route('lms.documents.show', $published))
            ->assertOk()
            ->assertJsonPath('data.related.0.title', 'Кому и какую скидку можно дать');
    }

    /**
     * Список задаётся целиком, и «сохранить» у того, кто соседа не видит, не
     * должно означать «убрать»: закрытый документ поставил рядом тот, кому он
     * открыт.
     */
    public function test_saving_the_list_keeps_neighbours_the_editor_cannot_see(): void
    {
        $owner = $this->author();

        $published = Regulation::factory()->published()->create(['author_id' => $owner->getKey()]);
        $closed = Regulation::factory()->published()->closed()->create(['author_id' => $owner->getKey()]);
        $open = Regulation::factory()->published()->create();

        $this->actingAs($owner)
            ->putJson(route('lms.documents.related.update', $published), ['documents' => [$closed->getKey()]])
            ->assertOk();

        // Другой редактор закрытого соседа не видит и присылает список без него.
        $stranger = $this->editor();

        $this->actingAs($stranger)
            ->putJson(route('lms.documents.related.update', $published), ['documents' => [$open->getKey()]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($owner)
            ->getJson(route('lms.documents.related.show', $published))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Наугад по номеру чужое закрытое правило не связать: иначе его название
     * приехало бы в ответе.
     */
    public function test_an_editor_cannot_link_a_document_that_is_closed_to_them(): void
    {
        $published = Regulation::factory()->published()->create();
        $closed = Regulation::factory()->published()->closed()->create(['title' => 'Не для всех']);

        $this->actingAs($this->editor())
            ->putJson(route('lms.documents.related.update', $published), ['documents' => [$closed->getKey()]])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('regulation_links', 0);
    }

    public function test_candidates_leave_out_the_document_itself_and_the_ones_already_linked(): void
    {
        $author = $this->author();

        $about = Regulation::factory()->published()->create([
            'author_id' => $author->getKey(),
            'title' => 'Клиент требует скидку',
        ]);
        $linked = Regulation::factory()->published()->create(['title' => 'Клиент конфликтует']);
        $free = Regulation::factory()->published()->create(['title' => 'Клиент требует товар по неверному ценнику']);

        $this->actingAs($author)
            ->putJson(route('lms.documents.related.update', $about), ['documents' => [$linked->getKey()]])
            ->assertOk();

        $this->actingAs($author)
            ->getJson(route('lms.documents.related.candidates', ['regulation' => $about, 'search' => 'клиент']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $free->getKey());
    }

    public function test_a_reader_cannot_change_the_list(): void
    {
        $regulation = Regulation::factory()->published()->create();
        $other = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.documents.related.update', $regulation), ['documents' => [$other->getKey()]])
            ->assertForbidden();
    }
}
