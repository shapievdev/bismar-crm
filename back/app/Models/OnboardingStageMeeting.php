<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingFormat;
use App\Models\Concerns\AwardsAchievement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заготовка встречи в этапе шаблона.
 *
 * С кем — либо конкретный человек (`with_user_id`), либо роль `mentor`
 * (`with_role`): наставник у каждого стажёра свой и подставляется при
 * назначении маршрута. Ровно одно из двух — следит SaveStage.
 */
#[Fillable([
    'stage_id', 'position', 'topic', 'format', 'place', 'with_user_id', 'with_role',
    'achievement_id',
])]
class OnboardingStageMeeting extends Model
{
    use AwardsAchievement;

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
