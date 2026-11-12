<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Материал этапа личного маршрута: курс или документ, который нужно изучить.
 *
 * `day_offset` — день, к которому материал стоит пройти. Ничего не отпирает:
 * открытие — дело этапа, а это срок на виду, чтобы неделю было как разложить.
 */
#[Fillable([
    'journey_stage_id', 'linkable_type', 'linkable_id', 'position', 'is_required', 'day_offset',
    'achievement_id',
])]
class OnboardingJourneyStageLink extends Model
{
    use AwardsAchievement;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_required' => 'boolean',
            'day_offset' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OnboardingJourneyStage, $this>
     */
    public function journeyStage(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourneyStage::class, 'journey_stage_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
