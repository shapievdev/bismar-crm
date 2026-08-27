<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['quiz_id', 'text', 'type', 'points', 'position'])]
class NewsQuizQuestion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => QuestionType::class];
    }

    /**
     * @return BelongsTo<NewsQuiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(NewsQuiz::class, 'quiz_id');
    }

    /**
     * @return HasMany<NewsQuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(NewsQuizOption::class, 'question_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return Collection<int, int>
     */
    public function correctOptionIds(): Collection
    {
        return $this->options->where('is_correct', true)->pluck('id')->values();
    }
}
