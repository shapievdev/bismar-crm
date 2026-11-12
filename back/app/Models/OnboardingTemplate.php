<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayMode;
use App\Enums\OnboardingTemplateStatus;
use Database\Factories\OnboardingTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Шаблон маршрута: программа по дням для должности или магазина.
 *
 * Трафарет, а не хозяин: его накладывают на личный маршрут сотрудника, и с
 * этой минуты копия живёт сама — поэтому обратной связи «чьи маршруты по
 * этому шаблону» здесь нет. Кто с какого трафарета снят, помнит этап маршрута
 * (`source_stage_id`). Версии связаны parent_id и имеют последовательный номер.
 */
#[Fillable([
    'kit_items',
    'settings', 'requires_graduation', 'store', 'graduation_achievement_id', 'parent_id', 'version', 'job_title',
    'title', 'slug', 'description', 'status', 'duration_days',
    'day_mode', 'allow_ahead', 'meeting_reminder_minutes', 'created_by',
])]
class OnboardingTemplate extends Model
{
    protected $attributes = ['version' => 1, 'requires_graduation' => false];

    /** @use HasFactory<OnboardingTemplateFactory> */
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
            'status' => OnboardingTemplateStatus::class,
            'day_mode' => DayMode::class,
            'allow_ahead' => 'boolean',
            'duration_days' => 'integer',
            'meeting_reminder_minutes' => 'integer',
            'kit_items' => 'array',
            'settings' => 'array',
            'requires_graduation' => 'boolean',
        ];
    }

    /**
     * Этапы по порядку. Удалённые мягко не видны — идущим журналам их отдаёт
     * связь журнального этапа, читающая withTrashed.
     *
     * @return HasMany<OnboardingStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(OnboardingStage::class, 'template_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
