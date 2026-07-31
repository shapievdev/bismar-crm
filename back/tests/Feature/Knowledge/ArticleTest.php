<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Enums\ArticleStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class ArticleTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_a_reader_sees_only_published_articles(): void
    {
        $published = KnowledgeArticle::factory()->published()->create();
        KnowledgeArticle::factory()->create(['title' => 'Черновик']);
        KnowledgeArticle::factory()->archived()->create();

        $response = $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.articles.index'))
            ->assertOk();

        $this->assertSame([$published->slug], array_column($response->json('data'), 'slug'));
    }

    public function test_an_editor_also_sees_drafts(): void
    {
        KnowledgeArticle::factory()->published()->create();
        KnowledgeArticle::factory()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->getJson(route('knowledge.articles.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_listings_omit_the_article_body(): void
    {
        KnowledgeArticle::factory()->published()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.articles.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.content');
    }

    public function test_a_reader_cannot_open_a_draft_and_is_not_told_it_exists(): void
    {
        $draft = KnowledgeArticle::factory()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.articles.show', $draft))
            ->assertNotFound();
    }

    public function test_an_editor_can_open_a_draft(): void
    {
        $draft = KnowledgeArticle::factory()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->getJson(route('knowledge.articles.show', $draft))
            ->assertOk()
            ->assertJsonPath('data.content', $draft->content);
    }

    public function test_a_user_without_knowledge_access_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('knowledge.articles.index'))
            ->assertForbidden();
    }

    public function test_an_article_can_be_created_and_gets_a_slug_from_its_title(): void
    {
        $category = KnowledgeCategory::factory()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->postJson(route('knowledge.articles.store'), [
                'title' => 'Как оформить возврат',
                'content' => 'Пошаговая инструкция.',
                'status' => ArticleStatus::Draft->value,
                'category_id' => $category->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'kak-oformit-vozvrat')
            ->assertJsonPath('data.status', ArticleStatus::Draft->value)
            ->assertJsonPath('data.category.id', $category->id);
    }

    public function test_slugs_stay_unique_across_identical_titles(): void
    {
        $author = $this->userWithRole(RoleEnum::Sales);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($author)->postJson(route('knowledge.articles.store'), [
                'title' => 'Регламент',
                'content' => 'Текст.',
                'status' => ArticleStatus::Draft->value,
            ])->assertCreated();
        }

        $this->assertSame(
            ['reglament', 'reglament-2'],
            KnowledgeArticle::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_publishing_requires_the_publish_permission(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->postJson(route('knowledge.articles.store'), [
                'title' => 'Черновик',
                'content' => 'Текст.',
                'status' => ArticleStatus::Published->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('knowledge_articles', 0);
    }

    public function test_publishing_stamps_the_publication_date_once(): void
    {
        $editor = $this->userWithRole(RoleEnum::Manager);
        $article = KnowledgeArticle::factory()->create();

        $this->actingAs($editor)
            ->putJson(route('knowledge.articles.update', $article), [
                'title' => $article->title,
                'content' => $article->content,
                'status' => ArticleStatus::Published->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ArticleStatus::Published->value);

        $publishedAt = $article->refresh()->published_at;
        $this->assertNotNull($publishedAt);

        // Back to draft and published again: the original date survives.
        $this->actingAs($editor)
            ->putJson(route('knowledge.articles.update', $article), [
                'title' => $article->title,
                'content' => $article->content,
                'status' => ArticleStatus::Draft->value,
            ])->assertOk();

        $this->actingAs($editor)
            ->putJson(route('knowledge.articles.update', $article), [
                'title' => $article->title,
                'content' => $article->content,
                'status' => ArticleStatus::Published->value,
            ])->assertOk();

        $this->assertTrue($publishedAt->equalTo($article->refresh()->published_at));
    }

    public function test_a_published_article_keeps_its_slug_when_retitled(): void
    {
        $article = KnowledgeArticle::factory()->published()->create(['slug' => 'staraya-statya']);

        $this->actingAs($this->userWithRole(RoleEnum::Manager))
            ->putJson(route('knowledge.articles.update', $article), [
                'title' => 'Совершенно новое название',
                'content' => $article->content,
                'status' => ArticleStatus::Published->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'staraya-statya');
    }

    public function test_a_draft_slug_follows_its_title(): void
    {
        $article = KnowledgeArticle::factory()->create(['slug' => 'chernovik']);

        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->putJson(route('knowledge.articles.update', $article), [
                'title' => 'Новое название',
                'content' => $article->content,
                'status' => ArticleStatus::Draft->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'novoe-nazvanie');
    }

    public function test_deleting_an_article_is_reversible(): void
    {
        $article = KnowledgeArticle::factory()->published()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Manager))
            ->deleteJson(route('knowledge.articles.destroy', $article))
            ->assertNoContent();

        $this->assertSoftDeleted($article);
    }

    public function test_an_author_without_delete_permission_cannot_delete(): void
    {
        $article = KnowledgeArticle::factory()->published()->create();

        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->deleteJson(route('knowledge.articles.destroy', $article))
            ->assertForbidden();

        $this->assertNotSoftDeleted($article);
    }

    public function test_articles_can_be_searched_by_title_and_body(): void
    {
        $match = KnowledgeArticle::factory()->published()->create(['title' => 'Возврат товара']);
        KnowledgeArticle::factory()->published()->create([
            'title' => 'Оплата',
            'excerpt' => null,
            'content' => 'Нерелевантный текст.',
        ]);

        $response = $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.articles.index', ['search' => 'возврат']))
            ->assertOk();

        $this->assertSame([$match->slug], array_column($response->json('data'), 'slug'));
    }

    public function test_articles_can_be_filtered_by_category(): void
    {
        $category = KnowledgeCategory::factory()->create();
        $match = KnowledgeArticle::factory()->published()->create(['category_id' => $category->id]);
        KnowledgeArticle::factory()->published()->create();

        $response = $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.articles.index', ['category' => $category->slug]))
            ->assertOk();

        $this->assertSame([$match->slug], array_column($response->json('data'), 'slug'));
    }

    public function test_deleting_a_category_keeps_its_articles(): void
    {
        $category = KnowledgeCategory::factory()->create();
        $article = KnowledgeArticle::factory()->published()->create(['category_id' => $category->id]);

        $this->actingAs($this->userWithRole(RoleEnum::Manager))
            ->deleteJson(route('knowledge.categories.destroy', $category))
            ->assertNoContent();

        $this->assertNull($article->refresh()->category_id);
        $this->assertNotSoftDeleted($article);
    }

    public function test_the_status_vocabulary_is_served_to_the_editor(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Viewer))
            ->getJson(route('knowledge.statuses'))
            ->assertOk()
            ->assertJsonCount(count(ArticleStatus::cases()), 'data')
            ->assertJsonPath('data.0.value', ArticleStatus::Draft->value);
    }

    public function test_an_administrator_may_publish(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        $this->assertTrue($admin->can(Permission::PublishKnowledge->value));

        $this->actingAs($admin)
            ->postJson(route('knowledge.articles.store'), [
                'title' => 'Объявление',
                'content' => 'Текст.',
                'status' => ArticleStatus::Published->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', ArticleStatus::Published->value);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }
}
