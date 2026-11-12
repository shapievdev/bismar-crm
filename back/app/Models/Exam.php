<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExamStatus;
use Carbon\CarbonInterface;
use Database\Factories\ExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Экзамен: проверка знаний, назначенная людям, — а не приложенная к материалу.
 *
 * Вопросы при экзамене — тот же Quiz, что у урока (morph `exam`): устройство
 * вопросов, оценка, попытки и очередь проверяющего от владельца не зависят.
 * Своё у экзамена то, чего у материала не бывает: окно сдачи, таймер попытки и
 * назначения со сроками. Планка зачёта и предел попыток живут на строке теста —
 * почему, объясняет миграция.
 */
#[Fillable([
    'title', 'slug', 'description', 'status',
    'opens_at', 'closes_at', 'time_limit_minutes', 'shuffle_questions', 'created_by',
])]
class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
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
            'status' => ExamStatus::class,
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'time_limit_minutes' => 'integer',
            'shuffle_questions' => 'boolean',
        ];
    }

    /**
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * @return HasMany<ExamAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ExamAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Принимает ли экзамен работы в эту минуту: опубликован и окно открыто.
     *
     * Личный срок назначения здесь не спрашивается намеренно: срок — обещание
     * человека, а не замок. Просрочивший сдаёт, пока окно открыто, — просто его
     * опоздание видно в результатах. Запереть дверь жёстче всегда можно краем
     * окна.
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        if (! $this->status->isOpenToSitters()) {
            return false;
        }

        if ($this->opens_at !== null && $moment->lt($this->opens_at)) {
            return false;
        }

        return $this->closes_at === null || $moment->lt($this->closes_at);
    }

    /**
     * Почему экзамен не принимает работы — словами, для отказа сдающему.
     * Null, когда принимает.
     */
    public function closedReasonAt(CarbonInterface $moment): ?string
    {
        if (! $this->status->isOpenToSitters()) {
            return 'Экзамен не идёт.';
        }

        if ($this->opens_at !== null && $moment->lt($this->opens_at)) {
            return 'Экзамен ещё не открылся.';
        }

        if ($this->closes_at !== null && ! $moment->lt($this->closes_at)) {
            return 'Экзамен уже закрылся.';
        }

        return null;
    }
}
