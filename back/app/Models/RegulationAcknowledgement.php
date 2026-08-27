<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Прочитал и понял» — весь прогресс, какой у регламента бывает.
 *
 * У курса прогресс складывается из пройденных уроков; здесь проходить нечего.
 * Эта же строка отвечает за то, пройден ли шаг плана обучения.
 *
 * Появляется один раз и не снимается: отменить ознакомление значит утверждать,
 * что прочитанное можно разучиться знать.
 */
#[Fillable(['regulation_id', 'user_id', 'acknowledged_at'])]
class RegulationAcknowledgement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Regulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(Regulation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
