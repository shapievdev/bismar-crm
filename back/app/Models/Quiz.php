<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\QuizKind;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['quizzable_type', 'quizzable_id', 'title', 'description', 'passing_score', 'max_attempts', 'kind', 'examiner_id'])]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => QuizKind::class];
    }

    /** Проверяет ли работу человек, а не приложение. */
    public function isAttestation(): bool
    {
        return $this->kind->isAttestation();
    }

    /**
     * Кому сдают работу. Null у обычного теста — там проверять некому и нечего.
     *
     * @return BelongsTo<User, $this>
     */
    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    /**
     * Планка теста — сто процентов: урок и документ зачитываются, когда все
     * ответы верны (решение пользователя 2026-08-31).
     *
     * Хранится и в строке (`passing_score`), чтобы оценка попытки оставалась
     * одним сравнением с записанным правилом, а не догадкой в коде. Автор её не
     * задаёт: выбор, которого нет, поле в форме обещать не должно. У проверки
     * при новости планка своя — там она про подтверждение, а не про зачёт.
     */
    public const PASSING_SCORE = 100;

    /**
     * Кому принадлежит тест: уроку или документу.
     *
     * Устройство теста от владельца не зависит — вопросы, попытки и разбор у
     * них одни и те же. Разное только то, что засчитывается сдачей: у урока
     * это прохождение урока, у документа — ознакомление с ним.
     *
     * @return MorphTo<Model, $this>
     */
    public function quizzable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Вправе ли этот человек править материал, при котором стоит тест, — а
     * значит, видеть ключ к ответам.
     *
     * Спрашивается у владельца, а не у прав на курсы: тест при справочнике
     * правит тот, кто ведёт справочники (решение пользователя 2026-09-11).
     * Владелец подгружается по требованию — теста на странице один, и лишний
     * запрос дешевле, чем показанный ключ.
     */
    public function isEditableBy(User $user): bool
    {
        $owner = $this->quizzable;

        return match (true) {
            $owner instanceof Regulation => $user->can($owner->kind->updatePermission()->value),
            // Проверка при версии правится тем же правом, что и сам документ:
            // версия — его часть, а не отдельный материал.
            $owner instanceof RegulationVersion => $owner->loadMissing('regulation')->regulation !== null
                && $user->can($owner->regulation->kind->updatePermission()->value),
            $owner instanceof Lesson => $user->can(Permission::UpdateCourses->value),
            // Владельца не нашли — значит материал удалён вместе с ним; ключ в
            // таком случае не показывает никто.
            default => false,
        };
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Total points on offer. Zero when the quiz has no questions yet, which
     * callers must treat as "not scorable" rather than dividing by it.
     */
    public function totalPoints(): int
    {
        return (int) $this->questions()->sum('points');
    }

    public function hasAttemptsLeft(User $user): bool
    {
        if ($this->max_attempts === null) {
            return true;
        }

        return $this->attempts()->where('user_id', $user->getKey())->count() < $this->max_attempts;
    }
}
