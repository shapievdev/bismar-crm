<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayMode;
use App\Enums\JourneyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Личный маршрут сотрудника: его карта этапов.
 *
 * Один на человека и навсегда — пустой, пока в него ничего не положили.
 * Наполняется руками или наложением шаблона; шаблонов за жизнь маршрута может
 * побывать несколько, поэтому «по какому шаблону идёт» у него не спрашивают —
 * спрашивают у этапа, с какого трафарета он снят.
 */
#[Fillable([
    'kit_items', 'kit_receipts',
    'duration_days', 'settings', 'requires_graduation', 'store', 'graduation_achievement_id',
    'user_id', 'started_at', 'day_mode', 'allow_ahead', 'meeting_reminder_minutes',
    'status', 'paused_at', 'completed_at',
    'access_confirmed_at', 'access_confirmed_by',
    'tshirt_issued_at', 'tshirt_issued_by',
    'barcode_issued_at', 'barcode_issued_by',
])]
class OnboardingJourney extends Model
{
    /**
     * Умолчания — на модели, а не только в схеме.
     *
     * Карта заводится одним `firstOrCreate(['user_id' => …])`, и значения из
     * `default()` базы в свежий объект не возвращаются: без этого у только что
     * созданного маршрута не было бы ни статуса, ни календаря — до первого
     * перечитывания из базы.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'day_mode' => 'calendar',
        'allow_ahead' => false,
        'requires_graduation' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'day_mode' => DayMode::class,
            'allow_ahead' => 'boolean',
            'meeting_reminder_minutes' => 'integer',
            'kit_items' => 'array',
            'kit_receipts' => 'array',
            'settings' => 'array',
            'duration_days' => 'integer',
            'requires_graduation' => 'boolean',
            'status' => JourneyStatus::class,
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'access_confirmed_at' => 'datetime',
            'tshirt_issued_at' => 'datetime',
            'barcode_issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OnboardingJourneyStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(OnboardingJourneyStage::class, 'journey_id')
            ->orderBy('position');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAlive(Builder $query): void
    {
        $query->whereIn('status', [JourneyStatus::Active, JourneyStatus::Paused]);
    }

    /** Пустой маршрут: карта заведена, но в ней ещё ничего нет. */
    public function isEmpty(): bool
    {
        return $this->stages()->doesntExist();
    }

    public function isCompleted(): bool
    {
        return $this->status === JourneyStatus::Completed;
    }

    /** Подтверждены ли обязательные пункты личного набора. */
}
