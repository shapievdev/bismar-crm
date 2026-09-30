<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\MaterialReview;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Материал, который согласуют перед публикацией: документ, справочник, курс.
 *
 * Трейт, а не два набора связей: круг устроен одинаково у всех, и разного между
 * ними ровно одно — чем «выложить» оборачивается для этого материала (см.
 * App\Actions\Lms\DecideMaterialApproval).
 */
trait HasApprovals
{
    /**
     * Все круги согласования, последний первым: прежние остаются историей — по
     * ним видно, что говорили в прошлый раз.
     *
     * @return MorphMany<MaterialReview, $this>
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(MaterialReview::class, 'reviewable')->orderByDesc('round');
    }

    /**
     * Последний круг — тот, о котором говорят «материал на согласовании» или
     * «его вернули».
     *
     * @return MorphOne<MaterialReview, $this>
     */
    public function latestReview(): MorphOne
    {
        return $this->morphOne(MaterialReview::class, 'reviewable')->latestOfMany('round');
    }

    /**
     * Открытый круг — или null, если материал сейчас никто не согласует.
     *
     * Спрашивается у последнего круга, а не отдельным запросом: номера растут, и
     * открытым может быть только последний. Уже прочитанный не перечитывается —
     * ресурсы и экраны грузят его вместе с материалом.
     */
    public function openReview(): ?MaterialReview
    {
        $review = $this->relationLoaded('latestReview')
            ? $this->latestReview
            : $this->latestReview()->first();

        return $review?->isOpen() === true ? $review : null;
    }
}
