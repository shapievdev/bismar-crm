<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Справочники — второй раздел, устроенный как документы.
 *
 * Устройство у них общее до последней мелочи, и проверять его дважды незачем:
 * статью, файлы, проверку и ответственных уже держат RegulationTest и соседи.
 * Здесь — ровно то, что появилось от второго вида: что разделы не
 * перемешиваются нигде, где материал показывают, и что справочник ходит в план
 * обучения своим именем.
 */
final class HandbookTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Клиент требует возврат',
            'summary' => 'Что делать прямо сейчас.',
            'status' => CourseStatus::Published->value,
            'visibility' => CourseVisibility::Public->value,

            // Категория обязательна, и она из дерева своего раздела.
            'category_id' => RegulationCategory::factory()->create(['kind' => MaterialKind::Handbook])->id,
        ], $overrides);
    }

    /* ---------- Разделы не перемешиваются ---------- */

    public function test_each_catalogue_shows_only_its_own_kind(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        Regulation::factory()->published()->handbook()->create(['title' => 'Клиент требует возврат']);

        $reader = $this->learner();

        $this->actingAs($reader)
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');

        $this->actingAs($reader)
            ->getJson(route('lms.handbooks.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Клиент требует возврат');
    }

    /**
     * Адрес материала уникален по всей таблице, и без сторожа страница
     * документа открывалась бы в разделе справочников: тот же текст, но крошки
     * и соседи — из чужого раздела.
     */
    public function test_a_document_is_not_found_in_the_handbooks_section(): void
    {
        $document = Regulation::factory()->published()->create();
        $handbook = Regulation::factory()->published()->handbook()->create();

        $reader = $this->learner();

        $this->actingAs($reader)
            ->getJson(route('lms.handbooks.show', $document))
            ->assertNotFound();

        $this->actingAs($reader)
            ->getJson(route('lms.documents.show', $handbook))
            ->assertNotFound();

        // А в своём разделе оба открываются.
        $this->actingAs($reader)->getJson(route('lms.documents.show', $document))->assertOk();
        $this->actingAs($reader)->getJson(route('lms.handbooks.show', $handbook))->assertOk();
    }

    public function test_a_handbook_is_created_in_the_section_it_was_asked_for(): void
    {
        $response = $this->actingAs($this->author())
            ->postJson(route('lms.handbooks.store'), $this->payload())
            ->assertCreated();

        $handbook = Regulation::query()->findOrFail($response->json('data.id'));

        $this->assertSame(MaterialKind::Handbook, $handbook->kind);
        $this->assertSame('/lms/handbooks/'.$handbook->slug, $handbook->path());
    }

    /* ---------- Своё дерево категорий ---------- */

    public function test_categories_belong_to_one_section_each(): void
    {
        RegulationCategory::factory()->create(['name' => 'Кассовая дисциплина']);

        $author = $this->author();

        $this->actingAs($author)
            ->postJson(route('lms.handbooks.categories.store'), ['name' => 'Клиент'])
            ->assertCreated();

        $this->actingAs($author)
            ->getJson(route('lms.handbooks.categories.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Клиент');

        $this->actingAs($author)
            ->getJson(route('lms.documents.categories.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Кассовая дисциплина');
    }

    public function test_a_handbook_cannot_be_filed_under_a_document_category(): void
    {
        $documentCategory = RegulationCategory::factory()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.handbooks.store'), $this->payload(['category_id' => $documentCategory->getKey()]))
            ->assertJsonValidationErrors('category_id');
    }

    /* ---------- План обучения ---------- */

    /* ---------- Соседи и корзина ---------- */

    public function test_a_document_cannot_be_put_next_to_a_handbook(): void
    {
        $author = $this->author();

        $handbook = Regulation::factory()->published()->handbook()->create(['author_id' => $author->getKey()]);
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        // Подсказка чужого раздела не предлагает…
        $this->actingAs($author)
            ->getJson(route('lms.handbooks.related.candidates', ['regulation' => $handbook, 'search' => 'кассовая']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // …и присланный наугад номер не связывается.
        $this->actingAs($author)
            ->putJson(route('lms.handbooks.related.update', $handbook), ['documents' => [$document->getKey()]])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_trash_says_which_section_a_row_came_from(): void
    {
        $author = $this->author();
        $handbook = Regulation::factory()->handbook()->create(['author_id' => $author->getKey()]);

        $this->actingAs($author)
            ->deleteJson(route('lms.handbooks.destroy', $handbook))
            ->assertNoContent();

        $this->actingAs($author)
            ->getJson(route('lms.trash.index'))
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'handbook');
    }
}
