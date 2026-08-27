<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class RegulationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Кассовая дисциплина',
            'summary' => 'Как принимать деньги и что делать с возвратом.',
            'content_json' => ['type' => 'doc', 'content' => []],
            'status' => CourseStatus::Published->value,
            'visibility' => CourseVisibility::Public->value,
        ], $overrides);
    }

    /* ---------- Каталог ---------- */

    public function test_a_published_regulation_is_in_the_catalogue(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');
    }

    public function test_a_draft_is_hidden_from_readers_and_shown_to_editors(): void
    {
        Regulation::factory()->create(['title' => 'Ещё пишется']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_reader_cannot_open_a_draft(): void
    {
        $draft = Regulation::factory()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $draft))
            ->assertForbidden();
    }

    /**
     * В каталоге статьи нет: из двадцати правил она весит больше всего
     * остального вместе взятого.
     */
    public function test_the_catalogue_leaves_the_article_out_and_the_card_carries_it(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $catalogue = $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index'))
            ->assertOk();

        $this->assertArrayNotHasKey('content_json', $catalogue->json('data.0'));

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $regulation))
            ->assertOk()
            ->assertJsonPath('data.content_json.type', 'doc');
    }

    public function test_the_catalogue_can_be_searched_in_russian(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        Regulation::factory()->published()->create(['title' => 'Охрана труда']);

        // Кириллица ищется без учёта регистра только через ICU: базы собраны с
        // C-сортировкой, где lower() и ILIKE складывают только латиницу.
        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index', ['search' => 'кассовая']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');
    }

    /* ---------- Закрытость ---------- */

    public function test_a_closed_regulation_is_invisible_to_outsiders(): void
    {
        $author = $this->author();
        $closed = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $closed))
            ->assertForbidden();

        $this->actingAs($author)
            ->getJson(route('lms.regulations.show', $closed))
            ->assertOk();
    }

    public function test_somebody_admitted_to_a_closed_regulation_can_read_it(): void
    {
        $reader = $this->learner();
        $closed = Regulation::factory()->published()->closed()->create();
        $closed->members()->attach($reader);

        $this->actingAs($reader)
            ->getJson(route('lms.regulations.show', $closed))
            ->assertOk();
    }

    /**
     * Приватность, которую отменяет должность, приватностью не является:
     * администратор закрытого правила не видит, а суперадминистратор видит.
     */
    public function test_an_administrator_does_not_bypass_a_closed_regulation(): void
    {
        $closed = Regulation::factory()->published()->closed()->create();

        $this->actingAs($this->administrator())
            ->getJson(route('lms.regulations.show', $closed))
            ->assertForbidden();

        $this->actingAs($this->superAdministrator())
            ->getJson(route('lms.regulations.show', $closed))
            ->assertOk();
    }

    /* ---------- Правка ---------- */

    public function test_an_editor_writes_a_regulation(): void
    {
        $response = $this->actingAs($this->author())
            ->postJson(route('lms.regulations.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.is_published', true);

        // Адрес — латиницей и без лишнего: конкретную таблицу транслитерации
        // не проверяем, она чужая и вправе меняться.
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) $response->json('data.slug'));

        $this->assertNotNull(Regulation::query()->sole()->published_at);
    }

    public function test_a_reader_cannot_write_regulations(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.store'), $this->payload())
            ->assertForbidden();
    }

    /**
     * Адрес заводится один раз: ссылку на правило могли уже отправить, и
     * опечатка в названии не повод её сломать.
     */
    public function test_renaming_a_regulation_keeps_its_address(): void
    {
        $editor = $this->author();

        $created = $this->actingAs($editor)
            ->postJson(route('lms.regulations.store'), $this->payload())
            ->assertCreated();

        $regulation = Regulation::query()->sole();

        $this->actingAs($editor)
            ->putJson(route('lms.regulations.update', $regulation), $this->payload([
                'title' => 'Кассовая дисциплина — редакция 2',
            ]))
            ->assertOk()
            ->assertJsonPath('data.slug', $created->json('data.slug'));
    }

    public function test_editing_does_not_move_the_publication_date(): void
    {
        $editor = $this->author();
        $regulation = Regulation::factory()->published()->create(['published_at' => now()->subMonth()]);
        $published = $regulation->published_at;

        $this->actingAs($editor)
            ->putJson(route('lms.regulations.update', $regulation), $this->payload(['title' => 'Поправлено']))
            ->assertOk();

        $this->assertTrue($published->equalTo($regulation->refresh()->published_at));
    }

    public function test_a_regulation_is_deleted_softly(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->deleteJson(route('lms.regulations.destroy', $regulation))
            ->assertNoContent();

        $this->assertSoftDeleted($regulation);
    }

    /* ---------- Категории ---------- */

    public function test_categories_are_returned_as_a_tree_with_counts(): void
    {
        $root = RegulationCategory::factory()->create(['name' => 'Касса']);
        $child = RegulationCategory::factory()->create(['name' => 'Возвраты', 'parent_id' => $root->id]);

        Regulation::factory()->published()->count(2)->create(['category_id' => $root->id]);
        Regulation::factory()->published()->create(['category_id' => $child->id]);

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.categories.index'))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('data.0.regulations_count'));
        $this->assertSame('Возвраты', $response->json('data.0.children.0.name'));
        $this->assertSame(1, $response->json('data.0.children.0.regulations_count'));
    }

    /**
     * Выбранная категория включает всё, что под ней: иначе родительская
     * выглядела бы пустой.
     */
    public function test_filtering_by_a_category_includes_its_children(): void
    {
        $root = RegulationCategory::factory()->create();
        $child = RegulationCategory::factory()->create(['parent_id' => $root->id]);

        Regulation::factory()->published()->create(['category_id' => $root->id]);
        Regulation::factory()->published()->create(['category_id' => $child->id]);
        Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.index', ['category' => $root->slug]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_category_cannot_be_nested_under_itself(): void
    {
        $category = RegulationCategory::factory()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.regulations.categories.update', $category), [
                'name' => $category->name,
                'parent_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_reader_cannot_touch_categories(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.categories.store'), ['name' => 'Своя'])
            ->assertForbidden();
    }

    /* ---------- Ознакомление ---------- */

    public function test_a_reader_marks_a_regulation_as_read(): void
    {
        $reader = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.acknowledge', $regulation))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);

        $this->assertTrue($regulation->isAcknowledgedBy($reader));

        $this->actingAs($reader)
            ->getJson(route('lms.regulations.show', $regulation))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);
    }

    public function test_marking_it_twice_leaves_one_record(): void
    {
        $reader = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)->postJson(route('lms.regulations.acknowledge', $regulation))->assertOk();
        $this->actingAs($reader)->postJson(route('lms.regulations.acknowledge', $regulation))->assertOk();

        $this->assertSame(1, $regulation->acknowledgements()->count());
    }

    public function test_a_draft_cannot_be_marked_as_read(): void
    {
        $draft = Regulation::factory()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.acknowledge', $draft))
            ->assertForbidden();
    }

    public function test_the_editor_sees_who_has_read_it(): void
    {
        $reader = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Вера']);
        $reader->givePermissionTo('courses.view');
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)->postJson(route('lms.regulations.acknowledge', $regulation))->assertOk();

        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.acknowledgements', $regulation))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ёлкина Вера')
            ->assertJsonCount(1, 'data');
    }

    public function test_a_reader_cannot_see_who_else_has_read_it(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.acknowledgements', $regulation))
            ->assertForbidden();
    }

    /* ---------- Люди ---------- */

    public function test_the_author_keeps_the_list_of_who_is_admitted(): void
    {
        $author = $this->author();
        $person = $this->learner();
        $regulation = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);

        $this->actingAs($author)
            ->putJson(route('lms.regulations.access.update', $regulation), ['members' => [$person->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $person->id);

        // Другой редактор списком не распоряжается: закрытость заводят под свой
        // круг людей — см. RegulationPolicy::manageAccess.
        $this->actingAs($this->author())
            ->putJson(route('lms.regulations.access.update', $regulation), ['members' => []])
            ->assertForbidden();
    }

    public function test_an_editor_appoints_who_answers_for_a_regulation(): void
    {
        $expert = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.regulations.experts.update', $regulation), ['members' => [$expert->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expert->id);

        // Список ответственных виден всякому, кто правило открыл.
        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $regulation))
            ->assertOk()
            ->assertJsonPath('data.experts.0.id', $expert->id);
    }

    /* ---------- Файлы ---------- */

    public function test_a_document_can_be_attached(): void
    {
        Storage::fake('s3');

        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.regulations.attachments.store', $regulation), [
                'file' => UploadedFile::fake()->create('регламент.pdf', 64, 'application/pdf'),
                'description' => 'Подписанная редакция',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'регламент.pdf');

        $this->assertSame(1, $regulation->attachments()->count());
        Storage::disk('s3')->assertExists($regulation->attachments()->sole()->path);
    }

    public function test_a_reader_cannot_attach_files(): void
    {
        Storage::fake('s3');

        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.attachments.store', $regulation), [
                'file' => UploadedFile::fake()->create('своё.pdf', 8, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_a_guest_sees_no_regulations(): void
    {
        $this->getJson(route('lms.regulations.index'))->assertUnauthorized();
    }
}
