<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вариант ответа.
 *
 * Без пометки «верный» — в отличие от варианта теста: у опроса верных нет. Это и
 * есть вся разница между двумя таблицами, и потому они не одна: пометка,
 * которая всегда false, однажды кем-нибудь прочитается как «ответ неверный».
 */
#[Fillable(['question_id', 'text', 'position'])]
class SurveyOption extends Model
{
    /**
     * @return BelongsTo<SurveyQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }
}
