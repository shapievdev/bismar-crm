<?php

declare(strict_types=1);

namespace App\Http\Requests\Exams;

use App\Enums\ExamStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeExamStatusRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ExamStatus::class)],
        ];
    }

    public function status(): ExamStatus
    {
        return ExamStatus::from((string) $this->validated('status'));
    }
}
