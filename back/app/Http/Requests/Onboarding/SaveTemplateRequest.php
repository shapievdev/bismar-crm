<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\DayMode;
use App\Support\Onboarding\RouteSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...RouteSettings::rules(),
            'kit_items' => ['prohibited'],
            'kit_receipts' => ['prohibited'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'requires_graduation' => ['prohibited'],
            'graduation_achievement_id' => ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            // 7–14 по ТЗ, но жёстко не запираем: сезонный магазин может
            // захотеть и 21. Предел — защита от опечатки, а не регламент.
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:60'],

            'day_mode' => ['sometimes', Rule::enum(DayMode::class)],
            'allow_ahead' => ['sometimes', 'boolean'],

            // За сколько минут напоминать о встрече: от «четверть часа» до суток.
            'meeting_reminder_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
