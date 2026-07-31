<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArticleStatus;
use Database\Factories\KnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'status', 'published_at'])]
class KnowledgeArticle extends Model
{
    /** @use HasFactory<KnowledgeArticleFactory> */
    use HasFactory, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Articles a reader without editing rights is allowed to see.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeReadable(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published);
    }

    /**
     * Columns a search term is matched against.
     */
    private const SEARCHABLE = ['title', 'excerpt', 'content'];

    /**
     * Case-insensitive search across the fields a reader would recognise.
     *
     * The databases are created with the C collation, whose lower() only folds
     * ASCII — plain ILIKE therefore misses Cyrillic entirely ('Возврат' would
     * not match 'возврат'). Collating to ICU first makes the comparison work
     * for the Russian content this CRM actually holds.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.$this->escapeLikeWildcards($term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            foreach (self::SEARCHABLE as $column) {
                $query->orWhereRaw(
                    sprintf('%s COLLATE "und-x-icu" ILIKE ?', $column),
                    [$pattern],
                );
            }
        });
    }

    /**
     * Keeps a literal % or _ in the user's term from behaving as a wildcard,
     * which would otherwise let "100%" match every article.
     */
    private function escapeLikeWildcards(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
