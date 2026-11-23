<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StaffDismissalReportInput extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'values' => 'array'];
    }
}
