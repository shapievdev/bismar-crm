<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingFormat;
use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Встреча стажёра: снапшот заготовки + живое состояние.
 *
 * Статуса «сорвана» нет намеренно: перенос — не неудача стажёра, перенесённая
 * встреча остаётся ожидающей, а `reschedules_count` — честная память о том,
 * сколько раз её двигали. Зачитывается встреча только подтверждением
 * ответственного.
 */
#[Fillable([
    'journey_stage_id', 'stage_meeting_id', 'topic', 'format', 'place',
    'with_user_id', 'scheduled_at', 'reschedules_count',
    'confirmed_at', 'confirmed_by', 'reminded_at', 'achievement_id',
])]
class OnboardingJourneyMeeting extends Model
{
    use AwardsAchievement;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => MeetingFormat::class,
            'scheduled_at' => 'datetime',
            'reschedules_count' => 'integer',
            'confirmed_at' => 'datetime',
            'reminded_at' => 'datetime',
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
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(User::class, 'with_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
