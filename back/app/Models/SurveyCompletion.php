<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка «этот человек опрос прошёл».
 *
 * Отдельно от ответов, и это главная мысль всего устройства. Опрос бывает
 * анонимным — тогда ответы не связаны с человеком вовсе, — но знать, кто прошёл,
 * приложение обязано в любом случае: обязательный опрос держит зачёт материала, и
 * снять его нечем, если спросить не у кого.
 *
 * Здесь же, уникальным ключом на пару «опрос — человек», живёт правило
 * «единожды»: повторных попыток у опроса не бывает.
 */
#[Fillable(['survey_id', 'user_id', 'completed_at'])]
class SurveyCompletion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
