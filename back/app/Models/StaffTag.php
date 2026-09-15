<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ручной тег сотрудника: «Кадровый резерв», «Испытательный срок продлён».
 *
 * Справочник правит кадровик — потому и таблица, а не перечисление в коде:
 * заводить новый тег через выкат значит не заводить его никогда.
 *
 * Тегов по стажу здесь нет: те считаются из даты приёма и не хранятся вовсе
 * (см. App\Enums\TenureTag). Смешай их в одной таблице — и однажды кадровик
 * снял бы «старожила» руками, а назавтра он вернулся бы сам.
 */
#[Fillable(['name', 'position'])]
class StaffTag extends Model
{
    /**
     * @return BelongsToMany<User, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_tag_user')
            ->withPivot('assigned_by_id')
            ->withTimestamps();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderByRaw('name collate "und-x-icu"');
    }
}
