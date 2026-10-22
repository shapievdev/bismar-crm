<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Имя из витрины, за которым стоит отдел, а иногда и человек.
 *
 * Строка заводится ровно тогда, когда имя кому-то сопоставили: витрина имён не
 * спрашивает, а держать пустую строку на каждое из восьмидесяти пяти значило бы
 * считать несопоставленное сопоставленным.
 *
 * Человек необязателен, и это не недоделка: под «Касса 2» продаёт смена, а не
 * сотрудник, и приписать её кому-то одному — соврать. Отдел у такой строки
 * есть, и в отчёт по отделам она попадает наравне со всеми.
 */
#[Fillable(['external_name', 'department_id', 'user_id'])]
class SalesPerson extends Model
{
    /** @var string */
    protected $table = 'sales_people';

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Строка пригодна для отчёта по отделам, только когда отдел назван:
     * начатое и брошенное сопоставление — то же нераспределённое.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAssigned(Builder $query): void
    {
        $query->whereNotNull('department_id');
    }
}
