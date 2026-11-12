<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Назначение экзамена человеку: обязанность сдать и, если есть, срок.
 *
 * Строка всегда про одного человека, каким бы способом его ни выбрали —
 * лично, отделом или группой: «отдел» — способ выбрать, а не субъект сдачи.
 *
 * Здесь же живёт окно начатой попытки (`started_at`/`expires_at`): таймер
 * тикает с «Начать», а строка попытки рождается только при отправке — ей
 * начало хранить негде. Окно одно, потому что и начатая попытка одна: вторая
 * «Начать» до отправки первой продолжает ту же, а не заводит новую.
 */
#[Fillable(['exam_id', 'user_id', 'assigned_by', 'due_at', 'started_at', 'expires_at'])]
class ExamAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Идёт ли попытка прямо сейчас — начата и время не вышло. */
    public function hasOpenWindowAt(CarbonInterface $moment): bool
    {
        return $this->started_at !== null
            && ($this->expires_at === null || $moment->lt($this->expires_at));
    }

    /** Начата, но время вышло, а работа так и не пришла. */
    public function hasExpiredWindowAt(CarbonInterface $moment): bool
    {
        return $this->started_at !== null
            && $this->expires_at !== null
            && ! $moment->lt($this->expires_at);
    }
}
