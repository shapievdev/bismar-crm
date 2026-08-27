<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Куда сходить после новости: курс, модуль, урок или регламент.
 *
 * Новость сообщает, что правило поменялось, — читателю нужно тут же открыть
 * само правило, а не искать его в каталоге.
 */
#[Fillable(['news_id', 'linkable_type', 'linkable_id', 'position'])]
class NewsLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<News, $this>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * Сам материал.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * В том порядке, в каком их перечислил автор.
     *
     * @param  Builder<NewsLink>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
