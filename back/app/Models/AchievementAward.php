<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Выданное достижение: кому, когда и за что.
 *
 * Моделью поверх `achievement_user`, а не безымянной сводной таблицей, потому
 * что это событие со своей жизнью: у него есть день, причина и строка, которая
 * его выдала. Выдача идёт через неё (AwardAchievement), а в профиль значки
 * приходят связью `User::achievements()` — читать удобнее списком.
 *
 * Наследует Pivot, чтобы той связи досталась она же: иначе `awarded_at` в
 * профиле приезжал бы сырой строкой из базы, мимо приведения типов.
 * `incrementing` возвращается на место — у таблицы есть свой `id`.
 *
 * Пары (достижение, человек) уникальны: второй шаг к тому же значку ничего не
 * добавляет, и ленивый пересчёт маршрута может звать выдачу сколько угодно.
 */
#[Fillable(['achievement_id', 'user_id', 'awarded_at', 'reason', 'source_type', 'source_id'])]
class AchievementAward extends Pivot
{
    public $incrementing = true;

    protected $table = 'achievement_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Achievement, $this>
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Строка, которая выдала: этап, материал, встреча, задание.
     *
     * Может указывать в пустоту — этап удаляют, а заслуга остаётся. Что
     * показать в профиле, знает снимок `reason`, а не эта связь.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
