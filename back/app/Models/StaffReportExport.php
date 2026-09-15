<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись в журнале выгрузок: кто, когда и что вынес из системы.
 *
 * Пишется на каждую выгрузку, а не только на ту, что с фамилиями: «выгрузил
 * сводку» — тоже факт, и без него в журнале осталась бы дырка вместо ответа на
 * вопрос «что вообще происходило в отчёте в тот день». Различает их колонка
 * `with_names` — по ней и спрашивают, когда спрашивают всерьёз.
 *
 * Модель только пишет и читает. Ни изменить запись, ни удалить её приложение не
 * умеет намеренно: журнал, который можно поправить, не журнал.
 */
#[Fillable(['user_id', 'format', 'filters', 'rows', 'with_names'])]
class StaffReportExport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'rows' => 'integer',
            'with_names' => 'boolean',
        ];
    }

    /**
     * Кто выгружал.
     *
     * Может быть пустым: человека удалили насовсем, а запись осталась — так и
     * задумано, иначе удаление учётной записи стирало бы след.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithNames(Builder $query): void
    {
        $query->where('with_names', true);
    }
}
