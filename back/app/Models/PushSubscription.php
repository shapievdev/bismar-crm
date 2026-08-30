<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписка устройства на уведомления.
 *
 * Принадлежит устройству, а не человеку: телефон, рабочий компьютер и домашний
 * — три разные строки. Адрес доставки выдаёт браузер, и он же — ключ: тот же
 * браузер, подписавшись заново, обновляет строку, а не заводит вторую.
 */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'device'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
