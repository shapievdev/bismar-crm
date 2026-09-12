<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * У документов и справочников свои права (решение пользователя 2026-09-11).
 *
 * До этого оба раздела отвечали правам на курсы, и «дать вести справочник для
 * зала» означало «дать переписать все правила компании». Теперь разделов три и
 * права у каждого свои — а значит, проверять надо не только то, что раздел
 * закрывается, но и то, что он закрывается **всюду**: материал показывают не
 * только в своём каталоге, но и соседом по теме, приложением к уроку, строкой
 * в корзине и источником в ответе консультанта.
 */
final class MaterialSectionRightsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Раздел закрывается сам по себе ---------- */

    public function test_the_right_to_courses_no_longer_opens_documents(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('lms.documents.index'))
            ->assertForbidden();
    }

    public function test_keeping_handbooks_does_not_open_documents(): void
    {
        $document = Regulation::factory()->published()->create();
        $handbook = Regulation::factory()->published()->handbook()->create();

        $keeper = $this->userWith(Permission::ViewHandbooks, Permission::UpdateHandbooks);

        $this->actingAs($keeper)
            ->getJson(route('lms.handbooks.show', $handbook))
            ->assertOk();

        $this->actingAs($keeper)
            ->getJson(route('lms.documents.show', $document))
            ->assertForbidden();

        $this->actingAs($keeper)
            ->putJson(route('lms.documents.update', $document), $this->payload())
            ->assertForbidden();
    }

    /**
     * Заводят материал тоже по разделам: право спрашивается до того, как
     * материал появился, и потому приходит от самого раздела.
     */
    public function test_creating_answers_to_the_right_of_its_own_section(): void
    {
        $author = $this->userWith(Permission::ViewHandbooks, Permission::CreateHandbooks);

        $this->actingAs($author)
            ->postJson(route('lms.documents.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($author)
            ->postJson(route('lms.handbooks.store'), $this->payload(MaterialKind::Handbook))
            ->assertCreated();
    }

    /**
     * Черновик виден тому, кто правит **этот** раздел.
     *
     * Раньше черновики всех материалов открывало одно право на курсы, и
     * редактор курсов читал недописанные правила компании.
     */
    public function test_a_draft_is_hidden_from_the_editor_of_another_section(): void
    {
        Regulation::factory()->create(['title' => 'Недописанные правила']);

        $this->actingAs($this->userWith(
            Permission::ViewDocuments,
            Permission::ViewHandbooks,
            Permission::UpdateHandbooks,
        ))
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------- Раздел закрывается и там, где материал лишь упомянут ---------- */

    /**
     * Справочник, приложенный к уроку, не показывается тому, кому справочники
     * закрыты: список у урока общий на оба раздела, и отобрать его маршрутом
     * нельзя.
     */
    public function test_an_attached_handbook_is_hidden_from_someone_without_the_section(): void
    {
        $lesson = $this->lesson();

        $handbook = Regulation::factory()->published()->handbook()->create(['title' => 'Клиент требует возврат']);
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $lesson->materials()->attach([
            $handbook->getKey() => ['position' => 0],
            $document->getKey() => ['position' => 1],
        ]);

        $response = $this->actingAs($this->userWith(Permission::ViewCourses, Permission::ViewDocuments))
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();

        $this->assertSame(
            ['Кассовая дисциплина'],
            array_column($response->json('data.materials'), 'title'),
            'Справочник показали тому, кому раздел справочников закрыт.',
        );

        // И статью его не вычитать, зная адрес.
        $this->actingAs($this->userWith(Permission::ViewCourses, Permission::ViewDocuments))
            ->getJson(route('lms.lessons.material', ['lesson' => $lesson, 'regulation' => $handbook]))
            ->assertNotFound();
    }

    /** Соседи «рядом по теме» переходят границу разделов — и права тоже. */
    public function test_a_related_handbook_is_hidden_from_someone_without_the_section(): void
    {
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $handbook = Regulation::factory()->published()->handbook()->create(['title' => 'Клиент требует возврат']);

        $document->related()->attach($handbook->getKey());

        $this->actingAs($this->userWith(Permission::ViewDocuments))
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(0, 'data.related');

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(1, 'data.related');
    }

    /* ---------- Корзина ---------- */

    public function test_the_bin_shows_only_the_sections_one_may_delete(): void
    {
        $author = $this->author();

        $course = Course::factory()->create(['title' => 'Работа с возражениями']);
        $document = Regulation::factory()->create(['title' => 'Кассовая дисциплина']);
        $handbook = Regulation::factory()->handbook()->create(['title' => 'Клиент требует возврат']);

        foreach ([$course, $document, $handbook] as $material) {
            $material->delete();
        }

        $keeper = $this->userWith(Permission::ViewHandbooks, Permission::DeleteHandbooks);

        $response = $this->actingAs($keeper)->getJson(route('lms.trash.index'))->assertOk();

        $this->assertSame(
            ['Клиент требует возврат'],
            array_column($response->json('data'), 'title'),
            'В корзине показали разделы, которых этот человек не удаляет.',
        );

        // И вернуть чужое нельзя, даже зная номер.
        $this->actingAs($keeper)
            ->postJson(route('lms.trash.documents.restore', ['document' => $document->getKey()]))
            ->assertNotFound();

        $this->actingAs($keeper)
            ->postJson(route('lms.trash.documents.restore', ['document' => $handbook->getKey()]))
            ->assertOk();

        $this->assertNotNull($handbook->fresh(), 'Справочник не вернулся из корзины.');
        $this->assertNotNull($author->fresh(), 'Автор никуда не делся.');
    }

    /** Ни одного права на удаление — в корзину не пускают вовсе. */
    public function test_the_bin_is_closed_without_any_right_to_delete(): void
    {
        $this->actingAs($this->editor())
            ->getJson(route('lms.trash.index'))
            ->assertForbidden();
    }

    /* ---------- Перенос прежних прав ---------- */

    /**
     * У кого было право на курсы, у того появляется такое же в обоих новых
     * разделах.
     *
     * Без переноса выкат тихо закрыл бы документы у всех, кроме
     * администраторов, — а те бы этого не заметили, потому что их пропускает
     * Gate::before. Миграция прогоняется здесь второй раз нарочно: повторный
     * прогон должен быть безвредным, иначе починка выката упрётся в первичный
     * ключ.
     */
    public function test_the_old_right_to_courses_is_carried_over_to_both_sections(): void
    {
        $editor = $this->userWith(Permission::ViewCourses, Permission::UpdateCourses);

        // Состояние до разделения: новых прав у человека нет.
        $editor->revokePermissionTo(
            Permission::ViewDocuments->value,
            Permission::UpdateDocuments->value,
            Permission::ViewHandbooks->value,
            Permission::UpdateHandbooks->value,
        );

        $migration = require database_path('migrations/2026_09_11_120000_let_each_material_section_carry_its_own_rights.php');

        $migration->up();
        $migration->up();

        $carried = $editor->fresh();

        $this->assertTrue($carried?->can(Permission::ViewDocuments->value));
        $this->assertTrue($carried?->can(Permission::UpdateHandbooks->value));

        // Чего не было, то и не появилось: удаления ему не давали.
        $this->assertFalse($carried?->can(Permission::DeleteDocuments->value));
    }

    /* ---------- Консультант ---------- */

    /**
     * Ответ не собирают из раздела, закрытого спрашивающему.
     *
     * Это не придирка к вежливости: ссылка в ответе ведёт на страницу, и
     * привести она может только туда, куда человека пустят. Иначе консультант
     * стал бы обходным путём к закрытому разделу.
     */
    public function test_the_consultant_never_quotes_a_closed_section(): void
    {
        Regulation::factory()->published()->handbook()->create([
            'title' => 'Клиент требует возврат',
            'content_json' => $this->article('Возврат оформляется в течение четырнадцати дней.'),
        ]);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->userWith(Permission::ViewCourses, Permission::ViewDocuments))
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат товара'])
            ->assertOk()
            ->assertJsonPath('data.sources', []);

        $this->assertFalse($transport->wasCalled(), 'Закрытый раздел ушёл в модель.');
    }

    /**
     * Спрашивать вправе тот, кому открыт хоть один раздел: требовать здесь
     * право на курсы значило бы закрыть консультанта от продавца, которому
     * открыты одни справочники.
     */
    public function test_one_open_section_is_enough_to_ask(): void
    {
        Regulation::factory()->published()->handbook()->create([
            'title' => 'Клиент требует возврат',
            'content_json' => $this->article('Возврат оформляется в течение четырнадцати дней.'),
        ]);

        $this->fakeModel(FakeAnthropicTransport::replying('Четырнадцать дней [источник 1].'));

        $this->actingAs($this->userWith(Permission::ViewHandbooks))
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат товара'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.title', 'Клиент требует возврат');
    }

    /** Ни одного раздела — и спрашивать нечего. */
    public function test_asking_is_closed_without_a_single_section(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат'])
            ->assertForbidden();
    }

    /* ---------- helpers ---------- */

    /**
     * @return array<string, mixed>
     */
    private function payload(MaterialKind $kind = MaterialKind::Document): array
    {
        return [
            'title' => 'Кассовая дисциплина',
            'summary' => 'Как считают кассу.',
            'status' => CourseStatus::Published->value,
            'visibility' => CourseVisibility::Public->value,
            'category_id' => RegulationCategory::factory()->create(['kind' => $kind])->id,
        ];
    }

    private function lesson(): Lesson
    {
        $course = Course::factory()->published()->create();

        return Lesson::factory()->create([
            'module_id' => CourseModule::factory()->create(['course_id' => $course->getKey()])->getKey(),
        ]);
    }

    /**
     * Статья материала в том виде, в каком её пишет редактор.
     *
     * @return array<string, mixed>
     */
    private function article(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['data-block-id' => 'b1', 'blockId' => 'b1'],
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }
}
