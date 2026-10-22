<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesPlanKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сколько выручки ждут с отдела или с продавца за месяц.
 *
 * Хранится первым числом месяца — план ставят на календарный месяц целиком, и
 * вторая граница у него выводится, а не задаётся: иначе однажды разойдутся.
 */
#[Fillable(['period', 'kind', 'department_id', 'sales_person_id', 'revenue'])]
class SalesPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'kind' => SalesPlanKind::class,
            'revenue' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<SalesPerson, $this>
     */
    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForMonth(Builder $query, CarbonImmutable $month): void
    {
        $query->where('period', $month->startOfMonth()->toDateString());
    }
}
