<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskConfirmer;
use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Задание этапа шаблона: что сделать и кто закрывает — сам стажёр или
 * ответственный подтверждением.
 */
#[Fillable(['stage_id', 'position', 'text', 'responsible_ids', 'confirm_by', 'is_required', 'achievement_id'])]
class OnboardingStageTask extends Model
{
    use AwardsAchievement;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'responsible_ids' => 'array',
            'confirm_by' => TaskConfirmer::class,
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OnboardingStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(OnboardingStage::class, 'stage_id')->withTrashed();
    }
}
