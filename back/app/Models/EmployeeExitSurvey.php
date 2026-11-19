<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class EmployeeExitSurvey extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['employee' => 'array', 'definition' => 'array', 'answers' => 'array',
            'dismissed_at' => 'datetime', 'submitted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('submitted_at')->whereNull('cancelled_at');
    }

    public function status(): string
    {
        return $this->submitted_at ? 'submitted' : ($this->cancelled_at ? 'cancelled' : 'pending');
    }
}
