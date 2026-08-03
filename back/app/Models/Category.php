<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top level of the knowledge base: categories group the material.
 */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'position'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /**
     * Every category beneath this one, however deep.
     *
     * @return HasMany<Category, $this>
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Ids of this category and everything under it, for filtering material by
     * a branch rather than a single node.
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        $ids = [$this->getKey()];

        foreach ($this->children as $child) {
            $ids = [...$ids, ...$child->branchIds()];
        }

        return $ids;
    }

    /**
     * Whether moving this category under $candidate would create a cycle —
     * either by parenting it to itself or to one of its own descendants.
     */
    public function wouldCycleUnder(?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        return in_array($candidateParentId, $this->branchIds(), strict: true);
    }

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
