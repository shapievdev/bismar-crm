<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnswerFeedback;
use App\Enums\AnswerPath;
use App\Enums\ConsultantOutcome;
use App\Support\Lms\CourseAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один заданный вопрос и что из него вышло.
 */
#[Fillable([
    'user_id',
    'question',
    'searched_as',
    'answer',
    'sources',
    'related',
    'experts',
    'private_course_ids',
    'outcome',
    'answered_from',
    'retrieved',
    'cited',
    'model',
    'duration_ms',
])]
class ConsultantQuestion extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => ConsultantOutcome::class,
            'answered_from' => AnswerPath::class,
            'feedback' => AnswerFeedback::class,
            'sources' => 'array',
            'related' => 'array',
            'experts' => 'array',
            'private_course_ids' => 'array',
            'hidden_at' => 'datetime',
            'feedback_at' => 'datetime',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution_seen_at' => 'datetime',
        ];
    }

    /**
     * Записи, ответ в которых собран только из открытого этому человеку.
     *
     * Вопрос сотрудника — его собственные слова, и скрывать их незачем; скрыть
     * надо ответ, а ответ и вопрос в журнале читаются вместе, одной строкой.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDrawnFromWhatIsOpenTo(Builder $query, User $reader): void
    {
        $access = CourseAccess::of($reader);

        if ($access->seesEverything()) {
            return;
        }

        // «Ни одного курса за пределами доступного». Сравнение массивов, а не
        // перебор: строк в журнале тысячи, и подзапрос на каждую обошёлся бы
        // дороже всего остального запроса.
        $query->whereRaw(<<<'SQL'
            NOT EXISTS (
                SELECT 1
                FROM jsonb_array_elements_text(consultant_questions.private_course_ids) AS restricted(id)
                WHERE restricted.id::bigint <> ALL (?::bigint[])
            )
        SQL, ['{'.implode(',', $access->privateCourseIds()).'}']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Урок, в который автор занёс дописанный ответ.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function resolutionLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'resolution_lesson_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** Дописан ли ответ на этот вопрос. */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Заявки, которые ещё никто не закрыл.
     *
     * То, с чего автор начинает разбор журнала: здесь не догадка о пробеле, а
     * прямая просьба живого человека, оставшегося без ответа.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingAnswer(Builder $query): void
    {
        $query->whereNotNull('requested_at')->whereNull('resolved_at');
    }

    /**
     * Вопросы, оставшиеся без ответа.
     *
     * То, ради чего журнал и открывают: список дыр в базе знаний.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUnanswered(Builder $query): void
    {
        $query->whereIn('outcome', [
            ConsultantOutcome::NothingFound->value,
            // Показанное близкое ответом не было: тема в базе есть, разобранного
            // вопроса в ней нет — ровно та дыра, ради которой список и открывают.
            ConsultantOutcome::Suggested->value,
            ConsultantOutcome::Unused->value,
        ]);
    }
}
