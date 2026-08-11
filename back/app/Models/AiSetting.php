<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiAuthScheme;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна строка настроек консультанта.
 *
 * Единственная — таблица заведена ради одной записи, потому что настройки
 * общие для всей системы. Отсутствие записи означает «всё из .env».
 */
#[Fillable(['model', 'embedding_model', 'base_url', 'api_key', 'auth_scheme', 'max_tokens', 'updated_by'])]
class AiSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Ключ лежит в базе шифрованным: дамп базы уходит на ноутбуки и в
            // бэкапы чаще, чем .env.
            'api_key' => 'encrypted',
            'auth_scheme' => AiAuthScheme::class,
        ];
    }

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

    /** Последние знаки ключа — чтобы человек узнал свой, не видя чужого. */
    public function keyHint(): ?string
    {
        $key = (string) $this->api_key;

        return $key === '' ? null : '…'.mb_substr($key, -4);
    }
}
