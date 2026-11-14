<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskConfirmer;
use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Задание стажёра: снапшот текста и правила + отметки.
 *
 * `submitted_at` — стажёр сказал «сделал»; у самостоятельных заданий это и
 * есть зачёт (confirmed_at ставится тем же действием), у заданий с
 * подтверждением зачёт ставит ответственный.
 */
#[Fillable([
    'journey_stage_id', 'stage_task_id', 'text', 'responsible_ids', 'confirm_by', 'is_required',
    'submitted_at', 'confirmed_at', 'confirmed_by', 'achievement_id',
])]
class OnboardingJourneyTask extends Model
{
    use AwardsAchievement;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'responsible_ids' => 'array',
            'confirm_by' => TaskConfirmer::class,
            'is_required' => 'boolean',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isDone(): bool
    {
        return $this->confirmed_at !== null;
    }
}
