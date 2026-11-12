<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Шаг маршрута, за который дают значок.
 *
 * Трейтом, а не восемью одинаковыми методами: шагов восемь — этап, материал,
 * встреча и задание, и всё это по обе стороны, у трафарета шаблона и у этапа
 * личного маршрута.
 *
 * `withTrashed` намеренно: спрятанное достижение исчезает из выбора, но тот,
 * кто открыл этап, должен видеть, что на шаге всё ещё висит именно оно, —
 * иначе значок пропал бы с экрана, продолжая выдаваться.
 *
 * @phpstan-require-extends Model
 */
trait AwardsAchievement
{
    /**
     * @return BelongsTo<Achievement, $this>
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class)->withTrashed();
    }
}
