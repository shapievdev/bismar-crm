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

            // Категория обязательна: каталог открывается её списком.
            'category_id' => RegulationCategory::factory()->create()->id,
        ], $overrides);
    }

    /* ---------- Каталог ---------- */

    public function test_a_published_regulation_is_in_the_catalogue(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');
    }

    public function test_a_draft_is_hidden_from_readers_and_shown_to_editors(): void
    {
        Regulation::factory()->create(['title' => 'Ещё пишется']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->author())
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_reader_cannot_open_a_draft(): void
    {
        $draft = Regulation::factory()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $draft))
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
            ->getJson(route('lms.documents.index'))
            ->assertOk();

        $this->assertArrayNotHasKey('content_json', $catalogue->json('data.0'));

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $regulation))
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
            ->getJson(route('lms.documents.index', ['search' => 'кассовая']))
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
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $closed))
            ->assertForbidden();

        $this->actingAs($author)
            ->getJson(route('lms.documents.show', $closed))
            ->assertOk();
    }

    public function test_somebody_admitted_to_a_closed_regulation_can_read_it(): void
    {
        $reader = $this->learner();
        $closed = Regulation::factory()->published()->closed()->create();
        $closed->members()->attach($reader);

        $this->actingAs($reader)
            ->getJson(route('lms.documents.show', $closed))
            ->assertOk();
    }

    /**
     * Закрытость отгораживает правило от компании, а не от руководства:
     * администратор читает его наравне с суперадминистратором, но кого туда
     * пускать, по-прежнему решает автор.
     */
    public function test_an_administrator_reads_a_closed_regulation(): void
    {
        $closed = Regulation::factory()->published()->closed()->create();

        foreach ([$this->administrator(), $this->superAdministrator()] as $reader) {
            $this->actingAs($reader)
                ->getJson(route('lms.documents.show', $closed))
                ->assertOk();
        }

        $this->actingAs($this->administrator())
            ->putJson(route('lms.documents.access.update', $closed), ['members' => [$this->learner()->id], 'groups' => []])
            ->assertForbidden();
    }

    /* ---------- Правка ---------- */

    public function test_an_editor_writes_a_regulation(): void
    {
        $response = $this->actingAs($this->author())
            ->postJson(route('lms.documents.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.is_published', true);

        // Адрес — латиницей и без лишнего: конкретную таблицу транслитерации
        // не проверяем, она чужая и вправе меняться.
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) $response->json('data.slug'));

        $this->assertNotNull(Regulation::query()->sole()->published_at);
    }

    /**
     * Имена блокам присваивает сервер, и присваивает при обычном сохранении.
     *
     * Не косметика: расшифровка документа собирается по блокам с именами, и
     * безымянный документ не попадал в поиск консультанта вовсе — до тех пор,
     * пока кто-нибудь не запустит руками `lms:reindex`.
     */
    public function test_saving_a_regulation_names_its_blocks_and_indexes_the_article(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.documents.store'), $this->payload([
                'content_json' => [
                    'type' => 'doc',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Возврат принимаем только по чеку.']],
                    ]],
                ],
            ]))
            ->assertCreated();

        $regulation = Regulation::query()->sole();

        $this->assertIsString($regulation->content_json['content'][0]['attrs']['blockId'] ?? null);
        $this->assertSame(1, $regulation->transcripts()->count());
    }

    /**
     * Присвоенное имя живёт, пока живёт блок: на него ссылается таблица
     * ответов, и правка соседнего абзаца не должна её обрывать.
     */
    public function test_editing_a_regulation_keeps_the_names_of_its_blocks(): void
    {
        $editor = $this->author();
        $article = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Возврат принимаем только по чеку.']],
            ]],
        ];

        $this->actingAs($editor)
            ->postJson(route('lms.documents.store'), $this->payload(['content_json' => $article]))
            ->assertCreated();

        $regulation = Regulation::query()->sole();
        $named = $regulation->content_json;
        $named['content'][0]['content'][0]['text'] = 'Возврат принимаем по чеку и по карте.';

        $this->actingAs($editor)
            ->putJson(route('lms.documents.update', $regulation), $this->payload(['content_json' => $named]))
            ->assertOk();

        $this->assertSame(
            $regulation->content_json['content'][0]['attrs']['blockId'],
            $regulation->fresh()->content_json['content'][0]['attrs']['blockId'],
        );
    }

    public function test_a_reader_cannot_write_regulations(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.store'), $this->payload())
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
            ->postJson(route('lms.documents.store'), $this->payload())
            ->assertCreated();

        $regulation = Regulation::query()->sole();

        $this->actingAs($editor)
            ->putJson(route('lms.documents.update', $regulation), $this->payload([
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
            ->putJson(route('lms.documents.update', $regulation), $this->payload(['title' => 'Поправлено']))
            ->assertOk();

        $this->assertTrue($published->equalTo($regulation->refresh()->published_at));
    }

    public function test_a_regulation_is_deleted_softly(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->deleteJson(route('lms.documents.destroy', $regulation))
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
            ->getJson(route('lms.documents.categories.index'))
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
            ->getJson(route('lms.documents.index', ['category' => $root->slug]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_category_cannot_be_nested_under_itself(): void
    {
        $category = RegulationCategory::factory()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.categories.update', $category), [
                'name' => $category->name,
                'parent_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_reader_cannot_touch_categories(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.categories.store'), ['name' => 'Своя'])
            ->assertForbidden();
    }

    /** Отметка «важная» есть и здесь: разделов, где категории показывают, три. */
    public function test_a_category_can_be_marked_important(): void
    {
        $category = RegulationCategory::factory()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.categories.update', $category), [
                'name' => $category->name,
                'is_important' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_important', true);

        $this->assertTrue($category->refresh()->is_important);
    }

    /** Важные идут первыми — в этом и смысл отметки. */
    public function test_important_categories_come_first(): void
    {
        RegulationCategory::factory()->create(['name' => 'Кадры', 'position' => 0]);
        RegulationCategory::factory()->important()->create(['name' => 'Охрана труда', 'position' => 1]);

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.documents.categories.index'))
            ->assertOk();

        $this->assertSame(
            ['Охрана труда', 'Кадры'],
            array_column($response->json('data'), 'name'),
        );
    }

    /**
     * Перестановка в списке шлёт только порядок, и отметка не должна слетать от
     * того, что кто-то подвинул категорию стрелкой.
     */
    public function test_reordering_a_category_leaves_its_mark_alone(): void
    {
        $category = RegulationCategory::factory()->important()->create(['position' => 0]);

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.categories.update', $category), [
                'name' => $category->name,
                'position' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_important', true);
    }

    /* ---------- Ознакомление ---------- */

    public function test_a_reader_marks_a_regulation_as_read(): void
    {
        $reader = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)
            ->postJson(route('lms.documents.acknowledge', $regulation))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);

        $this->assertTrue($regulation->isAcknowledgedBy($reader));

        $this->actingAs($reader)
            ->getJson(route('lms.documents.show', $regulation))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);
    }

    public function test_marking_it_twice_leaves_one_record(): void
    {
        $reader = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)->postJson(route('lms.documents.acknowledge', $regulation))->assertOk();
        $this->actingAs($reader)->postJson(route('lms.documents.acknowledge', $regulation))->assertOk();

        $this->assertSame(1, $regulation->acknowledgements()->count());
    }

    public function test_a_draft_cannot_be_marked_as_read(): void
    {
        $draft = Regulation::factory()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.acknowledge', $draft))
            ->assertForbidden();
    }

    public function test_the_editor_sees_who_has_read_it(): void
    {
        $reader = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Вера']);
        $reader->givePermissionTo('documents.view');
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($reader)->postJson(route('lms.documents.acknowledge', $regulation))->assertOk();

        $this->actingAs($this->author())
            ->getJson(route('lms.documents.acknowledgements', $regulation))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ёлкина Вера')
            ->assertJsonCount(1, 'data');
    }

    public function test_a_reader_cannot_see_who_else_has_read_it(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.acknowledgements', $regulation))
            ->assertForbidden();
    }

    /* ---------- Люди ---------- */

    public function test_the_author_keeps_the_list_of_who_is_admitted(): void
    {
        $author = $this->author();
        $person = $this->learner();
        $regulation = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.access.update', $regulation), ['members' => [$person->id], 'groups' => []])
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonPath('data.people.0.id', $person->id);

        // Другой редактор списком не распоряжается: закрытость заводят под свой
        // круг людей — см. RegulationPolicy::manageAccess.
        $this->actingAs($this->author())
            ->putJson(route('lms.documents.access.update', $regulation), ['members' => [], 'groups' => []])
            ->assertForbidden();
    }

    /**
     * Открыть закрытый материал — то же решение, что «кого сюда пускать».
     *
     * Иначе список допущенных стерёгся бы напрасно: впущенный редактор снимал
     * бы закрытость одним сохранением формы и открывал материал всей компании.
     * То же правило, что и у курсов, — см. StoreCourseRequest.
     */
    public function test_opening_a_closed_regulation_is_the_authors_decision(): void
    {
        $author = $this->author();
        $closed = Regulation::factory()->published()->closed()->create([
            'author_id' => $author->id,
            'title' => 'Закрытая инструкция',
        ]);

        $editor = $this->author();
        $closed->members()->attach($editor);

        $saved = fn (string $visibility, string $title): array => $this->payload([
            'title' => $title,
            'visibility' => $visibility,
            'category_id' => $closed->category_id,
        ]);

        foreach ([$editor, $this->administrator()] as $stranger) {
            $this->actingAs($stranger)
                ->putJson(route('lms.documents.update', $closed), $saved('public', $closed->title))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('visibility');
        }

        // Править материал допущенный редактор при этом не разучился: поле
        // приходит в каждом сохранении, и неизменённое ему не мешает.
        $this->actingAs($editor)
            ->putJson(route('lms.documents.update', $closed), $saved('private', 'Закрытая инструкция по кассе'))
            ->assertOk()
            ->assertJsonPath('data.title', 'Закрытая инструкция по кассе')
            ->assertJsonPath('data.is_private', true);

        $this->actingAs($author)
            ->putJson(route('lms.documents.update', $closed), $saved('public', 'Закрытая инструкция по кассе'))
            ->assertOk()
            ->assertJsonPath('data.is_private', false);

        // Открыли — видно всем.
        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $closed))
            ->assertOk();
    }

    public function test_an_editor_appoints_who_answers_for_a_regulation(): void
    {
        $expert = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.experts.update', $regulation), ['members' => [$expert->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expert->id);

        // Список ответственных виден всякому, кто правило открыл.
        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $regulation))
            ->assertOk()
            ->assertJsonPath('data.experts.0.id', $expert->id);
    }

    /* ---------- Файлы ---------- */

    public function test_a_document_can_be_attached(): void
    {
        Storage::fake('s3');

        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.documents.attachments.store', $regulation), [
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
            ->postJson(route('lms.documents.attachments.store', $regulation), [
                'file' => UploadedFile::fake()->create('своё.pdf', 8, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_a_guest_sees_no_regulations(): void
    {
        $this->getJson(route('lms.documents.index'))->assertUnauthorized();
    }
}
