<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use Database\Factories\NewsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'author_id', 'title', 'slug', 'excerpt', 'content_json',
    'status', 'published_at', 'is_pinned', 'audience', 'requires_acknowledgement',
])]
class News extends Model
{
    /** @use HasFactory<NewsFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'news';

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
            'content_json' => 'array',
            'status' => NewsStatus::class,
            'audience' => NewsAudience::class,
            'published_at' => 'datetime',
            'is_pinned' => 'boolean',
            'requires_acknowledgement' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Кому адресована новость, когда она не для всех.
     *
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'news_recipients')->withTimestamps();
    }

    /**
     * @return HasMany<NewsAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(NewsAcknowledgement::class);
    }

    /**
     * @return HasMany<NewsAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(NewsAttachment::class)->orderBy('id');
    }

    /**
     * Куда сходить после новости: курс, модуль, урок или регламент.
     *
     * @return HasMany<NewsLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(NewsLink::class)->inOrder();
    }

    /**
     * @return HasOne<NewsQuiz, $this>
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(NewsQuiz::class);
    }

    public function isPublished(): bool
    {
        return $this->status->isVisibleToReaders();
    }

    /**
     * Адресована ли новость этому человеку.
     *
     * Отдельно от того, опубликована ли она: составитель читает и черновик,
     * а адресат — только вышедшее, и складывать это в одно условие значит
     * однажды показать черновик тому, кому он предназначался.
     */
    public function isAddressedTo(User $user): bool
    {
        if (! $this->audience->isSelected()) {
            return true;
        }

        return $this->recipients()->whereKey($user->getKey())->exists();
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->acknowledgements()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Новости, которые этот человек вправе прочитать.
     *
     * Только опубликованные и только адресованные ему. Составителю этого мало —
     * он видит и черновики, — но у него на то отдельное право и отдельный
     * запрос: расширять этот значило бы полагаться на то, что вызывающий не
     * забудет проверить право.
     *
     * @param  Builder<News>  $query
     */
    public function scopeReadableBy(Builder $query, User $user): void
    {
        $query
            ->where('status', NewsStatus::Published)
            ->where(fn (Builder $inner) => $inner
                ->where('audience', NewsAudience::Everyone)
                ->orWhereHas('recipients', fn (Builder $people) => $people->whereKey($user->getKey())));
    }

    /**
     * Порядок ленты: закреплённое сверху, дальше по свежести публикации.
     *
     * @param  Builder<News>  $query
     */
    public function scopeInFeedOrder(Builder $query): void
    {
        $query
            ->orderByDesc('is_pinned')
            // NULLS LAST: у черновика даты публикации нет, и Postgres на
            // убывающей сортировке поставил бы его во главе ленты.
            ->orderByRaw('published_at DESC NULLS LAST')
            ->orderByDesc('id');
    }
}
