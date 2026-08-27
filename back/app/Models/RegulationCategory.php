<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseVisibility;
use App\Support\Lms\RegulationAccess;
use Database\Factories\RegulationCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Верхний уровень регламентов: категории группируют правила.
 *
 * Дерево своё, а не общее с учебными категориями (решение пользователя
 * 2026-08-27): в тех ищут, чему научиться, в этих — по какому правилу
 * работать, и один список на двоих читался бы как свалка.
 */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'position'])]
class RegulationCategory extends Model
{
    /** @use HasFactory<RegulationCategoryFactory> */
    use HasFactory;

    protected $table = 'regulation_categories';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<RegulationCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<RegulationCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /**
     * Всё, что лежит ниже, на любую глубину.
     *
     * Со счётчиком по пути: корневой запрос считает своё, и без того же счёта
     * здесь вложенная категория докладывала бы о нуле независимо от того,
     * держит она что-нибудь или нет.
     *
     * @return HasMany<RegulationCategory, $this>
     */
    public function descendants(): HasMany
    {
        return $this->children()->withVisibleRegulationCounts()->with('descendants');
    }

    /**
     * @return HasMany<Regulation, $this>
     */
    public function regulations(): HasMany
    {
        return $this->hasMany(Regulation::class, 'category_id');
    }

    /**
     * Сколько в категории правил — по тому, что видно спрашивающему.
     *
     * Считать всё подряд нельзя: раздел показывал бы «3» тому, кто откроет его
     * и найдёт один, — и само это число рассказывало бы о существовании
     * закрытых регламентов.
     *
     * Читателя берём из запроса, а не из аргумента: счётчик навешивается и
     * внутри отношения descendants(), которое рекурсивно грузит само себя и
     * передать туда ничего не может.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithVisibleRegulationCounts(Builder $query): void
    {
        $reader = auth()->user();

        $query->withCount(['regulations' => function (Builder $regulations) use ($reader): void {
            $reader instanceof User
                ? RegulationAccess::of($reader)->applyTo($regulations)
                : $regulations->where('regulations.visibility', CourseVisibility::Public->value);
        }]);
    }

    /**
     * Эта категория и всё, что под ней, — чтобы отбирать по ветке, а не по
     * одному узлу.
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
     * Не замкнётся ли дерево, если подчинить эту категорию кандидату — самой
     * себе или собственному потомку.
     */
    public function wouldCycleUnder(?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        return in_array($candidateParentId, $this->branchIds(), strict: true);
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
