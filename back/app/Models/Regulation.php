<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Support\Lms\RegulationAccess;
use Database\Factories\RegulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило, по которому работают.
 *
 * Сам себе урок: ни модулей, ни частей — статья, файлы и отметка «ознакомлен».
 * Состояние и приватность берут те же перечисления, что курс: читаются они
 * одинаково, и вторая пара названий для того же самого разошлась бы с первой на
 * первой же правке.
 */
#[Fillable([
    'author_id', 'category_id', 'title', 'slug', 'summary',
    'content_json', 'status', 'visibility', 'published_at',
])]
class Regulation extends Model
{
    /** @use HasFactory<RegulationFactory> */
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
            'content_json' => 'array',
            'status' => CourseStatus::class,
            'visibility' => CourseVisibility::class,
            'published_at' => 'datetime',
        ];
    }

    public function isPrivate(): bool
    {
        return $this->visibility->isPrivate();
    }

    public function isPublished(): bool
    {
        return $this->status->isOpenToLearners();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Проверка при документе.
     *
     * Есть — значит ознакомление засчитывается сдачей, а не нажатием кнопки
     * (решение пользователя 2026-09-01). Устройство теста то же, что у урока:
     * одни вопросы, одни попытки, один разбор.
     *
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * @return BelongsTo<RegulationCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RegulationCategory::class, 'category_id');
    }

    /**
     * Кого пустили в закрытый регламент. Автора здесь нет: его доступ следует
     * из авторства, и строка о нём означала бы, что доступ можно снять.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'regulation_members')->withTimestamps();
    }

    /**
     * Кому писать, если написанного не хватило.
     *
     * @return BelongsToMany<User, $this>
     */
    public function experts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'regulation_experts')->withTimestamps();
    }

    /**
     * @return HasMany<RegulationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RegulationAttachment::class)->orderBy('id');
    }

    /**
     * @return HasMany<RegulationAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(RegulationAcknowledgement::class);
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->acknowledgements()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Опубликованное — то, что читают, а не пишут.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CourseStatus::Published);
    }

    /**
     * Оставляет только то, что этому человеку открыто. Про состояние ничего не
     * говорит: черновик виден редактору, и складывать это в одно условие
     * значит однажды показать черновик всем.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        RegulationAccess::of($user)->applyTo($query);
    }

    /**
     * Поиск по названию и краткому описанию.
     *
     * Сверяется с ICU: базы собраны с C-сортировкой, где lower() и ILIKE
     * складывают только латиницу, — иначе «касса» не нашла бы «Кассовую».
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.$term.'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw('title COLLATE "und-x-icu" ILIKE ?', [$pattern])
                ->orWhereRaw('summary COLLATE "und-x-icu" ILIKE ?', [$pattern]);
        });
    }
}
