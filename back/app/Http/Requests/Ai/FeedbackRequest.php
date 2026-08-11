<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Enums\AnswerFeedback;
use Illuminate\Foundation\Http\FormRequest;

final class FeedbackRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'helpful' => ['required', 'boolean'],
        ];
    }

    public function feedback(): AnswerFeedback
    {
        return $this->boolean('helpful') ? AnswerFeedback::Helpful : AnswerFeedback::Unhelpful;
    }
}
