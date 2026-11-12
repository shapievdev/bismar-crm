<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\News\ListNews;
use App\Mcp\Tools\News\PublishNews;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
