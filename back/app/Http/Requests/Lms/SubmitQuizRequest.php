<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitQuizRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Question id => chosen option ids. Unanswered questions may be
            // omitted; they simply score nothing.
            'answers' => ['present', 'array'],
            'answers.*' => ['array'],
            'answers.*.*' => ['integer'],
        ];
    }

    /**
     * @return array<int, list<int>>
     */
    public function answers(): array
    {
        /** @var array<int|string, list<int|string>> $answers */
        $answers = $this->validated('answers', []);

        $normalised = [];

        foreach ($answers as $questionId => $optionIds) {
            $normalised[(int) $questionId] = array_map(intval(...), $optionIds);
        }

        return $normalised;
    }
}
