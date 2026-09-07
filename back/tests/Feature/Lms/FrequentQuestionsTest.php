<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\MaterialKind;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * «Частые вопросы» — материалы, приколотые к документу списком.
 *
 * Проверяется то, чем этот список отличается от соседства: он односторонний,
 * держит заданный порядок и не знает границы разделов. И то же, что у соседей:
 * читателю он не выдаёт ни черновик, ни чужое закрытое правило.
 */
final class FrequentQuestionsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_pinned_material_appears_on_the_page(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create([
            'author_id' => $author->getKey(),
            'title' => 'Работа на кассе',
        ]);
        $answer = Regulation::factory()->published()->create(['title' => 'Не работает касса']);

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$answer->getKey()]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Не работает касса');

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.questions.0.title', 'Не работает касса')
            ->assertJsonPath('data.questions.0.path', '/lms/documents/'.$answer->slug);
    }

    /**
     * Связь односторонняя: список ведут у той страницы, с которой приходят, и
     * встречного блока у приколотого материала не появляется.
     */
    public function test_pinning_does_not_answer_back(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $answer = Regulation::factory()->published()->create();

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$answer->getKey()]])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $answer))
            ->assertOk()
            ->assertJsonCount(0, 'data.questions');
    }

    /**
     * Порядок задают руками: список читают сверху вниз, и первым стоит то, с
     * чем приходят чаще.
     */
    public function test_the_list_keeps_the_order_it_was_given(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        [$first, $second, $third] = Regulation::factory()->count(3)->published()->create()->all();

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), [
                'documents' => [$third->getKey(), $first->getKey(), $second->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $second->id);

        // И тот же порядок — у читателя, а не тот, в котором строки легли в базу.
        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.questions.0.id', $third->id)
            ->assertJsonPath('data.questions.2.id', $second->id);
    }

    /**
     * Раздел не важен: к правилу прикалывают справочник — сотруднику нужен
     * ответ, а не раздел, в котором он лежит.
     */
    public function test_a_handbook_may_be_pinned_to_a_document(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $handbook = Regulation::factory()->published()->create([
            'kind' => MaterialKind::Handbook,
            'title' => 'Где поесть в смену',
        ]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$handbook->getKey()]])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.questions.0.kind', 'handbook')
            // Ссылка ведёт в чужой раздел, и собрана она на сервере: разделов
            // два, и по месту её больше не сложить.
            ->assertJsonPath('data.questions.0.path', '/lms/handbooks/'.$handbook->slug);
    }

    /** Сам на себя материал не ссылается. */
    public function test_a_document_is_not_its_own_question(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$document->getKey()]])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Черновик читателю не показывают: строка вела бы туда, куда его не пустят.
     */
    public function test_a_draft_stays_out_of_the_readers_list(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $draft = Regulation::factory()->create(['title' => 'Ещё пишется']);
        $published = Regulation::factory()->published()->create(['title' => 'Готовое']);

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), [
                'documents' => [$draft->getKey(), $published->getKey()],
            ])
            ->assertOk()
            // Редактор видит оба: черновик в его списке стоять может.
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.title', 'Готовое');
    }

    /**
     * Подсказка поиска предлагает оба раздела — и не предлагает уже
     * приколотое.
     */
    public function test_candidates_come_from_both_sections(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $handbook = Regulation::factory()->published()->create([
            'kind' => MaterialKind::Handbook,
            'title' => 'Нужна фирменная футболка',
        ]);
        $pinned = Regulation::factory()->published()->create(['title' => 'Нужна печать']);

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$pinned->getKey()]])
            ->assertOk();

        $found = $this->actingAs($author)
            ->getJson(route('lms.documents.questions.candidates', [$document, 'search' => 'нужна']))
            ->assertOk()
            ->json('data');

        $this->assertSame([$handbook->id], array_column($found, 'id'));
    }

    /** Список ведёт тот, кто правит материал. */
    public function test_a_reader_cannot_pin_anything(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.documents.questions.update', $document), [
                'documents' => [Regulation::factory()->published()->create()->getKey()],
            ])
            ->assertForbidden();
    }

    /** Пустой список — это «убрать все строки», а не «поле не прислали». */
    public function test_an_empty_list_clears_the_block(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->getKey()]);
        $answer = Regulation::factory()->published()->create();

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => [$answer->getKey()]])
            ->assertOk();

        $this->actingAs($author)
            ->putJson(route('lms.documents.questions.update', $document), ['documents' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
