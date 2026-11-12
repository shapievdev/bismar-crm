<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ссылка этапа на материал базы знаний — курс или документ/справочник.
 *
 * Только эти два вида: у них «изучено» измеримо (курс — по записи и урокам,
 * документ — по ознакомлению и тесту), и обязательная ссылка участвует в
 * прогрессе этапа. Устроена как NewsLink: полиморф + позиция.
 */
#[Fillable([
    'stage_id', 'linkable_type', 'linkable_id', 'position', 'is_required', 'day_offset',
    'achievement_id',
])]
class OnboardingStageLink extends Model
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
     * @return BelongsTo<OnboardingStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(OnboardingStage::class, 'stage_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
