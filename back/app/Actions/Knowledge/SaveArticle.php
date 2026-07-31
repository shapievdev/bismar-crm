<?php

declare(strict_types=1);

namespace App\Actions\Knowledge;

use App\Data\Knowledge\ArticleData;
use App\Enums\ArticleStatus;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

final readonly class SaveArticle
{
    public function __construct(private SlugGenerator $slugGenerator) {}

    public function create(ArticleData $data, User $author): KnowledgeArticle
    {
        return DB::transaction(function () use ($data, $author): KnowledgeArticle {
            $article = new KnowledgeArticle([
                'title' => $data->title,
                'slug' => $this->slugGenerator->generate($data->title, KnowledgeArticle::class),
                'excerpt' => $data->excerpt,
                'content' => $data->content,
                'status' => $data->status,
                'category_id' => $data->categoryId,
                'author_id' => $author->getKey(),
                'published_at' => $this->publishedAt($data->status, null),
            ]);

            $article->save();

            return $article->load('category', 'author');
        });
    }

    public function update(KnowledgeArticle $article, ArticleData $data): KnowledgeArticle
    {
        return DB::transaction(function () use ($article, $data): KnowledgeArticle {
            // The slug is part of the article's public address, so it only
            // follows the title while the article has never been published.
            if ($article->published_at === null && $article->title !== $data->title) {
                $article->slug = $this->slugGenerator->generate(
                    $data->title,
                    KnowledgeArticle::class,
                    $article->getKey(),
                );
            }

            $article->fill([
                'title' => $data->title,
                'excerpt' => $data->excerpt,
                'content' => $data->content,
                'status' => $data->status,
                'category_id' => $data->categoryId,
                'published_at' => $this->publishedAt($data->status, $article->published_at),
            ]);

            $article->save();

            return $article->load('category', 'author');
        });
    }

    /**
     * Stamps the first publication and preserves it afterwards, so an article
     * pulled back to draft and republished keeps its original date.
     */
    private function publishedAt(ArticleStatus $status, ?\DateTimeInterface $current): ?\DateTimeInterface
    {
        if ($status !== ArticleStatus::Published) {
            return $current;
        }

        return $current ?? now();
    }
}
