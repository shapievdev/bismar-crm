<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Models\Course;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * План сотрудника целиком: порядок присланного и есть порядок шагов.
 */
final class UpdateLearningPlanRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать весь план», и
            // отличить его от «поле не прислали» иначе нечем.
            'courses' => ['present', 'array'],
            'courses.*' => ['integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Сначала об ошибках: `validated()` на провалившемся разборе
                // бросает исключение, а не отдаёт то, что уцелело.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $wanted = $this->courses();

                if ($wanted === []) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();

                $visible = Course::query()->visibleTo($actor)->whereKey($wanted)->pluck('id')->all();

                // Назначить можно только то, что видишь сам. Иначе чужой
                // приватный курс попадал бы в план по угаданному номеру — и
                // сотрудник увидел бы в плане название, которое ему закрыто.
                if (array_diff($wanted, array_map(intval(...), $visible)) !== []) {
                    $validator->errors()->add('courses', 'В плане есть курс, которого вы не видите.');
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function courses(): array
    {
        /** @var list<int> $courses */
        $courses = $this->validated('courses', []);

        return array_values(array_unique(array_map(intval(...), $courses)));
    }
}
