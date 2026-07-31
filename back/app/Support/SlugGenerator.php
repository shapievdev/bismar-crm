<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final readonly class SlugGenerator
{
    /**
     * Builds a URL-safe slug that is unique within the model's table.
     *
     * Cyrillic titles transliterate via Str::slug; a title that leaves nothing
     * behind (emoji, punctuation only) falls back to a random token so the
     * article still gets a usable address.
     *
     * @param  class-string<Model>  $model
     */
    public function generate(string $source, string $model, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while ($this->exists($slug, $model, $ignoreId)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function exists(string $slug, string $model, ?int $ignoreId): bool
    {
        $query = $model::query()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        // Soft-deleted rows still occupy the unique index, so they must count.
        if (in_array(SoftDeletes::class, class_uses_recursive($model), strict: true)) {
            $query->withTrashed();
        }

        return $query->exists();
    }
}
