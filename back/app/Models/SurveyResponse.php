<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отправленное одним человеком — целиком, снимком.
 *
 * Снимком по той же причине, что и ответы попытки: вопрос могли переписать после,
 * а сказанное человеком от этого не меняется.
 *
 * `user_id` пуст у анонимного опроса, и это не пропуск, а его исполнение:
 * обещание «не запишем, кто что ответил» нельзя сдержать, храня рядом и то и
 * другое. Что человек прошёл опрос, помнит отдельная отметка — SurveyCompletion.
 */
#[Fillable(['survey_id', 'user_id', 'answers', 'submitted_at'])]
class SurveyResponse extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * Кто ответил. Null у анонимного опроса — и у того, чью учётную запись
     * с тех пор удалили.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ответ на один вопрос — как он был отправлен.
     *
     * Ключи в снимке строковые: json_decode возвращает номера вопросов строками,
     * и сравнивать их с числом напрямую нельзя.
     *
     * @return array{options?: list<int>, other?: ?string, scale?: ?int, text?: ?string}
     */
    public function answerTo(int $questionId): array
    {
        $answers = $this->answers ?? [];
        $answer = $answers[(string) $questionId] ?? [];

        return is_array($answer) ? $answer : [];
    }
}
