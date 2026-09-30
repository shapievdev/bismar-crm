<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Окно, в котором суперадминистратор работал от имени сотрудника.
 *
 * Не «событие входа», а именно окно: всё, что случилось с сотрудником между
 * `started_at` и `ended_at`, мог сделать не он. Открытая строка (`ended_at`
 * пуст) означает, что окно идёт прямо сейчас.
 */
#[Fillable(['impersonator_id', 'user_id', 'ip', 'started_at', 'ended_at'])]
class Impersonation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Кто вошёл — суперадминистратор. */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /** Под кем. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }
}
