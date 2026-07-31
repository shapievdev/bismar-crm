<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\CourseStatus;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCourseRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(CourseStatus::class)],
        ];
    }

    /**
     * Publishing is a privilege of its own: an author may build a course
     * without being able to release it to learners.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $wantsToPublish = $this->input('status') === CourseStatus::Published->value;

            if ($wantsToPublish && $this->user()?->cannot(Permission::PublishCourses->value)) {
                $validator->errors()->add('status', 'У вас нет права публиковать курсы.');
            }
        });
    }
}
