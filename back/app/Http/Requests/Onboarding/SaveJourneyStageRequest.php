<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\MeetingFormat;
use App\Enums\TaskConfirmer;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Этап личного маршрута: шапка, материалы, встречи, задания.
 *
 * Отличий от этапа шаблона три, и все от того, что здесь живой человек: день
 * необязателен (маршрут бывает и без дат), у встречи нет роли «наставник» —
 * подставлять её некуда, — зато есть время: у шаблона его взять не от чего, а
 * у маршрута есть календарь.
 */
final class SaveJourneyStageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hidden = Rule::exists('achievements', 'id')->whereNull('deleted_at');

        return [
            'id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'is_final_exam' => ['sometimes', 'boolean'],
            'responsible_ids' => ['sometimes', 'array', 'max:30'],
            'responsible_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('dismissed_at')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'day_offset' => ['nullable', 'integer', 'min:1', 'max:365'],
            'opens_at' => ['nullable', 'date'],
            'position' => ['nullable', 'integer', 'min:1'],

            'links' => ['sometimes', 'array'],
            'links.*.type' => ['required', Rule::in(array_keys(PlannableMaterial::KINDS))],
            'links.*.id' => ['required', 'integer'],
            'links.*.is_required' => ['sometimes', 'boolean'],
            // День материала — срок на виду, не замок: см. миграцию.
            'links.*.day_offset' => ['nullable', 'integer', 'min:1', 'max:365'],

            'meetings' => ['sometimes', 'array'],
            'meetings.*.id' => ['nullable', 'integer'],
            'meetings.*.topic' => ['required', 'string', 'max:255'],
            'meetings.*.format' => ['required', Rule::enum(MeetingFormat::class)],
            'meetings.*.place' => ['nullable', 'string', 'max:255'],
            'meetings.*.with_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('dismissed_at'),
            ],
            /*
             * Время встречи — здесь же, вместе с этапом: назначить его можно
             * сразу, не дожидаясь, пока выберут собеседника, и поправить потом
             * в той же форме. Пусто — «время уточняется».
             */
            'meetings.*.scheduled_at' => ['nullable', 'date'],

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

    /**
     * Присланное с видами, переведёнными в morph: так его ждёт действие.
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
