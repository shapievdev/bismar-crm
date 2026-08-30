<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\Permission;
use App\Models\Course;
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
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['required', Rule::enum(CourseStatus::class)],
            // Не присылают — значит, не меняют: у нового курса это «открытый»,
            // у существующего остаётся то, что было.
            'visibility' => ['sometimes', Rule::enum(CourseVisibility::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->checkWhoMayPublish($validator);
            $this->checkWhoMayCloseTheCourse($validator);
        });
    }

    /**
     * Publishing is a privilege of its own: an author may build a course
     * without being able to release it to learners.
     *
     * Право нужно на сам перевод в «опубликован», а не на то, чтобы курс там
     * оставался. Иначе редактор без него не мог бы сохранить у опубликованного
     * курса ни одной правки: статус уходит в каждом запросе, и проверка
     * отклоняла бы неизменённое значение — а обойти её можно было бы только
     * сняв курс с публикации.
     */
    private function checkWhoMayPublish(Validator $validator): void
    {
        if ($this->input('status') !== CourseStatus::Published->value) {
            return;
        }

        $course = $this->route('course');

        if ($course instanceof Course && $course->status === CourseStatus::Published) {
            return;
        }

        if ($this->user()?->cannot(Permission::PublishCourses->value)) {
            $validator->errors()->add('status', 'У вас нет права публиковать курсы.');
        }
    }

    /**
     * Открыть курс или закрыть — то же решение, что и «кого сюда пускать», и
     * принимает его тот же человек: автор. Редактор, которого в приватный курс
     * впустили, правит материал, но круг допущенных не меняет — в том числе и
     * тем, что открыл бы курс всем сразу.
     */
    private function checkWhoMayCloseTheCourse(Validator $validator): void
    {
        $course = $this->route('course');

        if (! $course instanceof Course || ! $this->has('visibility')) {
            return;
        }

        if ($this->input('visibility') === $course->visibility->value) {
            return;
        }

        if ($this->user()?->cannot('manageAccess', $course)) {
            $validator->errors()->add('visibility', 'Менять доступ к курсу может только его автор.');
        }
    }
}
