<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsAcknowledgementSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка «прочитал и понял».
 *
 * Строка появляется один раз и не снимается: отменить ознакомление — значит
 * утверждать, что прочитанное можно разучиться знать.
 */
#[Fillable(['news_id', 'user_id', 'source', 'acknowledged_at'])]
class NewsAcknowledgement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => NewsAcknowledgementSource::class,
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<News, $this>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
