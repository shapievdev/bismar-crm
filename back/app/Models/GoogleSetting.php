<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна строка настроек Google.
 *
 * Единственная — таблица заведена ради одной записи, потому что интеграция
 * общая для всей системы. Отсутствие записи означает «всё из .env».
 *
 * Что именно здесь лежит и почему открыто, объяснено в миграции: оба значения
 * публичны по устройству — они всё равно уезжают в браузер.
 */
#[Fillable(['client_id', 'api_key', 'updated_by'])]
class GoogleSetting extends Model
{
    /**
     * Настройки системы, существующие или пустые.
     *
     * Никогда не null, чтобы вызывающему не приходилось помнить о случае
     * «ещё ни разу не настраивали».
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
