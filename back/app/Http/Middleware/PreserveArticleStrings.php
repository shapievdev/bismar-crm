<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;

final class PreserveArticleStrings extends ConvertEmptyStringsToNull
{
    protected function transform($key, $value)
    {
        return str_starts_with($key, 'content_json.') ? $value : parent::transform($key, $value);
    }
}
