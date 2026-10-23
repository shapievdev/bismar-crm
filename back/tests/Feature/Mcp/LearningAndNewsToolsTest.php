<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\Learning\PlannableMaterials;
use App\Mcp\Tools\Learning\SetLearningPlan;
use App\Mcp\Tools\Learning\ShowLearningPlan;
use App\Mcp\Tools\News\ListNews;
use App\Mcp\Tools\News\PublishNews;
use App\Models\Course;
use App\Models\News;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Планы обучения и новости через коннектор.
 *
 * Два правила, за которыми стоит следить особенно: план правит администратор, а
 * не всякий, кому доверено обучение, и выпуск новости — отдельное решение, а не
 * побочный результат её заведения.
 */
final class LearningAndNewsToolsTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    /* ---------- План обучения ---------- */

    public function test_reading_plans_and_changing_them_are_different_rights(): void
    {
        // Доверено обучение — читает планы, но не решает, что проходить.
        Bismar::actingAs($this->userWith(Permission::ManageEnrollments))
            ->tools()
            ->assertRegistered([ShowLearningPlan::class, PlannableMaterials::class])
            ->assertNotRegistered(SetLearningPlan::class);

        Bismar::actingAs($this->administrator())
            ->tools()
            ->assertRegistered(SetLearningPlan::class);
    }

    public function test_an_administrator_composes_a_plan_and_it_is_read_back(): void
    {
        $learner = User::factory()->create();
        $course = Course::factory()->published()->create(['title' => 'Работа с возражениями']);
        $document = Regulation::factory()->published()->create(['title' => 'Правила возврата']);

        Bismar::actingAs($this->administrator())
            ->tool(SetLearningPlan::class, [
                'employee_id' => $learner->getKey(),
                'items' => [
                    ['kind' => 'document', 'id' => $document->getKey()],
                    ['kind' => 'course', 'id' => $course->getKey()],
                ],
            ])
            ->assertOk()
            ->assertHasNoErrors();

        // Порядок присланного и есть порядок шагов.
        Bismar::actingAs($this->administrator())
            ->tool(ShowLearningPlan::class, ['employee_id' => $learner->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('steps', fn (Collection $steps): bool => $steps->count() === 2
                    && $steps[0]['title'] === 'Правила возврата'
                    && $steps[1]['title'] === 'Работа с возражениями'
                    && $steps[0]['kind'] === 'document'
                    && $steps[1]['kind'] === 'course')
                ->where('finished', false)
                ->etc());
    }

    /** Пустой список — это «убрать весь план», и так и должно работать. */
    public function test_an_empty_list_removes_the_plan(): void
    {
        $learner = User::factory()->create();
        $document = Regulation::factory()->published()->create();

        $administrator = $this->administrator();

        Bismar::actingAs($administrator)
            ->tool(SetLearningPlan::class, [
                'employee_id' => $learner->getKey(),
                'items' => [['kind' => 'document', 'id' => $document->getKey()]],
            ])
            ->assertOk();

        Bismar::actingAs($administrator)
            ->tool(SetLearningPlan::class, ['employee_id' => $learner->getKey(), 'items' => []])
            ->assertOk();

        $this->assertSame(0, $learner->planItems()->count());
    }

    /**
     * Назначить можно только то, что видишь сам: иначе чужой закрытый материал
     * попадал бы в план по угаданному номеру, и сотрудник увидел бы название,
     * которое ему закрыто.
     *
     * Для администратора закрытость не помеха — руководству видно всё
     * (RegulationAccess), — а вот убранное в корзину не видно и ему: это и
     * проверяется, потому что случай житейский — материал нашли и тут же
     * выбросили.
     */
    public function test_material_the_composer_cannot_see_is_refused(): void
    {
        $learner = User::factory()->create();

        $thrownAway = Regulation::factory()->published()->create();
        $thrownAway->delete();

        Bismar::actingAs($this->administrator())
            ->tool(SetLearningPlan::class, [
                'employee_id' => $learner->getKey(),
                'items' => [['kind' => 'document', 'id' => $thrownAway->getKey()]],
            ])
            ->assertHasErrors();

        $this->assertSame(0, $learner->planItems()->count());
    }

    /* ---------- Новости ---------- */

    public function test_news_answer_to_their_own_permission(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewCourses))
            ->tools()
            ->assertNotRegistered([ListNews::class, PublishNews::class]);

        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tools()
            ->assertRegistered([ListNews::class, PublishNews::class]);
    }

    /**
     * Новость заводится черновиком: публикация будит компанию, и это должно
     * быть отдельным решением, а не побочным результатом заведения.
     */
    public function test_a_news_item_is_drafted_by_default(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tool(PublishNews::class, [
                'title' => 'Переезд склада',
                'article' => "Склад переезжает в субботу.\n\nВ пятницу отгрузки не будет.",
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', NewsStatus::Draft->value)
                ->etc());

        $saved = News::query()->sole();

        $this->assertNull($saved->published_at);
        // Текст разложен в абзацы — пустая строка их и делит.
        $this->assertCount(2, $saved->content_json['content'] ?? []);
    }

    public function test_a_news_item_is_published_when_asked(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tool(PublishNews::class, [
                'title' => 'Новые правила возврата',
                'article' => 'С понедельника действует новая форма.',
                'status' => 'published',
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', NewsStatus::Published->value)
                ->etc());

        $this->assertNotNull(News::query()->sole()->published_at);
    }

    /** Адресная новость обязана выйти к кому-то — иначе её не увидит никто. */
    public function test_an_addressed_news_item_needs_recipients_to_be_published(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tool(PublishNews::class, [
                'title' => 'Только отделу продаж',
                'audience' => 'selected',
                'status' => 'published',
            ])
            ->assertHasErrors([
                'Адресную новость нельзя выпустить, не назвав адресатов: укажите сотрудников, отделы или группы.',
            ]);

        $this->assertSame(0, News::query()->count());
    }

    /** А черновиком — можно: кому именно, выясняют, пока она черновик. */
    public function test_an_addressed_draft_may_wait_for_its_recipients(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tool(PublishNews::class, ['title' => 'Пока не решили кому', 'audience' => 'selected'])
            ->assertOk()
            ->assertHasNoErrors();
    }

    /** Правка непришедшего текста его не стирает. */
    public function test_editing_the_title_keeps_the_article(): void
    {
        $keeper = $this->userWith(Permission::ManageNews);

        Bismar::actingAs($keeper)
            ->tool(PublishNews::class, ['title' => 'Было', 'article' => 'Текст новости.'])
            ->assertOk();

        $news = News::query()->sole();

        Bismar::actingAs($keeper)
            ->tool(PublishNews::class, ['news_id' => $news->getKey(), 'title' => 'Стало'])
            ->assertOk();

        $fresh = $news->fresh();

        $this->assertSame('Стало', $fresh?->title);
        $this->assertCount(1, $fresh?->content_json['content'] ?? []);
    }

    public function test_the_list_counts_who_acknowledged(): void
    {
        News::factory()->published()->create(['title' => 'Про отгрузки']);

        Bismar::actingAs($this->userWith(Permission::ManageNews))
            ->tool(ListNews::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('total', 1)
                ->where('news.0.title', 'Про отгрузки')
                ->where('news.0.acknowledgements', 0)
                ->etc());
    }
}
