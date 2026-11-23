<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\MeetingFormat;
use App\Enums\TaskConfirmer;
use App\Models\OnboardingStageMeeting;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveStageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hidden = Rule::exists('achievements', 'id')->whereNull('deleted_at');

        return [
            'id' => ['nullable', 'integer'],
            'is_final_exam' => ['sometimes', 'boolean'],
            'responsible_ids' => ['sometimes', 'array', 'max:30'],
            'responsible_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('dismissed_at')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'day_offset' => ['required', 'integer', 'min:1', 'max:60'],
            'position' => ['nullable', 'integer', 'min:1'],

            // Материалы приходят видами экрана (course/document/handbook) —
            // в morph-вид их переводит links(), как делал план обучения.
            'links' => ['sometimes', 'array'],
            'links.*.type' => ['required', Rule::in(array_keys(PlannableMaterial::KINDS))],
            'links.*.id' => ['required', 'integer'],
            'links.*.is_required' => ['sometimes', 'boolean'],
            // День материала внутри маршрута — срок на виду, не замок.
            'links.*.day_offset' => ['nullable', 'integer', 'min:1', 'max:365'],

            'meetings' => ['sometimes', 'array'],
            'meetings.*.id' => ['nullable', 'integer'],
            'meetings.*.topic' => ['required', 'string', 'max:255'],
            'meetings.*.format' => ['required', Rule::enum(MeetingFormat::class)],
            'meetings.*.place' => ['nullable', 'string', 'max:255'],
            'meetings.*.participant_ids' => ['sometimes', 'array', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && count($value) !== count(array_unique($value, SORT_REGULAR))) {
                    $fail('Участники одной встречи не должны повторяться.');
                }
            }],
            'meetings.*.participant_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('dismissed_at')->whereNull('deleted_at')],
            'meetings.*.with_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('dismissed_at'),
            ],
            'meetings.*.scheduled_time' => ['nullable', 'date_format:H:i'],
            'meetings.*.with_role' => ['nullable', Rule::in([OnboardingStageMeeting::ROLE_MENTOR])],

            'tasks' => ['sometimes', 'array'],
            'tasks.*.id' => ['nullable', 'integer'],
            'tasks.*.text' => ['required', 'string', 'max:1000'],
            'tasks.*.responsible_ids' => ['sometimes', 'array', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && count($value) !== count(array_unique($value, SORT_REGULAR))) {
                    $fail('В списке ответственных не должно быть повторов.');
                }
            }],
            'tasks.*.responsible_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('dismissed_at')->whereNull('deleted_at')],
            'tasks.*.confirm_by' => ['required', Rule::enum(TaskConfirmer::class)],
            'tasks.*.is_required' => ['sometimes', 'boolean'],

            /*
             * Значки: за этап целиком, за его тест и за любое дело внутри.
             * Спрятанное достижение не выбрать — оно осталось в каталоге
             * только ради тех, у кого уже есть.
             */
            'achievement_id' => ['nullable', 'integer', $hidden],
            'quiz_achievement_id' => ['nullable', 'integer', $hidden],
            'links.*.achievement_id' => ['nullable', 'integer', $hidden],
            'meetings.*.achievement_id' => ['nullable', 'integer', $hidden],
            'tasks.*.achievement_id' => ['nullable', 'integer', $hidden],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('meetings', []) as $index => $meeting) {
                $hasPerson = ! empty($meeting['participant_ids']) || ! empty($meeting['with_user_id']);
                $hasRole = ! empty($meeting['with_role']);

                // Нужны участники или наставник. Наставника можно добавить к списку;
                // старый одиночный собеседник с ролью остаётся неоднозначным.
                if ((! $hasPerson && ! $hasRole) || (! empty($meeting['with_user_id']) && $hasRole)) {
                    $validator->errors()->add(
                        "meetings.{$index}.with_user_id",
                        'Выберите участников встречи или роль «наставник».',
                    );
                }
            }
        });
    }

    /**
     * Присланное — с видами, переведёнными в morph: так его ждёт SaveStage.
     *
     * @return array<string, mixed>
     */
    public function stage(): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->validated();

        $attributes['links'] = array_map(
            static fn (array $link): array => [
                'type' => PlannableMaterial::morphAliasFor((string) $link['type']),
                'id' => (int) $link['id'],
                'is_required' => (bool) ($link['is_required'] ?? true),
                'day_offset' => isset($link['day_offset']) ? (int) $link['day_offset'] : null,
                'achievement_id' => isset($link['achievement_id']) ? (int) $link['achievement_id'] : null,
            ],
            $attributes['links'] ?? [],
        );

        return $attributes;
    }
}
