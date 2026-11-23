<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingFormat;
use App\Models\Concerns\AwardsAchievement;
use App\Models\Concerns\HasMeetingParticipants;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заготовка встречи в этапе шаблона.
 *
 * Участники задаются списком; при назначении можно дополнительно подставить
 * наставника стажёра. Старое поле with_user_id сохраняет совместимость.
 */
#[Fillable([
    'stage_id', 'position', 'topic', 'format', 'place', 'with_user_id', 'with_role',
    'participant_ids', 'scheduled_time', 'achievement_id',
])]
class OnboardingStageMeeting extends Model
{
    use AwardsAchievement, HasMeetingParticipants;

    /** Роль «наставник стажёра» — разворачивается в users.mentor_id. */
    public const ROLE_MENTOR = 'mentor';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'format' => MeetingFormat::class,
            'participant_ids' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(User::class, 'with_user_id');
    }
}
