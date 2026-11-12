<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingTemplateStatus;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\Onboarding\ProfessionalKit;
use App\Support\SlugGenerator;

/**
 * Создаёт шаблон маршрута или правит его шапку.
 *
 * Этапы — отдельным действием (SaveStage): шапка меняется парой полей, а этап
 * несёт материалы, встречи, задания и тест, и слать всё это ради смены
 * названия незачем. Правка шапки не трогает идущих: их правила снапшочены при
 * назначении.
 */
final readonly class SaveTemplate
{
    public function __construct(private SlugGenerator $slugs) {}

    /**
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     duration_days?: int,
     *     day_mode?: string,
     *     allow_ahead?: bool,
     *     meeting_reminder_minutes?: ?int,
     * } $attributes
     */
    public function handle(?OnboardingTemplate $template, array $attributes, User $actor): OnboardingTemplate
    {
        $template ??= new OnboardingTemplate([
            'status' => OnboardingTemplateStatus::Draft,
            'created_by' => $actor->getKey(),
        ]);

        $required = $attributes['requires_graduation'] ?? ($template->exists ? $template->requires_graduation : true);
        $kit = ProfessionalKit::validated(
            $attributes['kit_items'] ?? ProfessionalKit::definitions($template->kit_items), (bool) $required,
        );

        $template->fill([
            'kit_items' => $kit,
            'title' => $attributes['title'],
            'job_title' => $attributes['job_title'] ?? null,
            'store' => $attributes['store'] ?? null,
            'requires_graduation' => $attributes['requires_graduation'] ?? ($template->exists ? $template->requires_graduation : true),
            'settings' => $attributes['settings'] ?? $template->settings,
            'graduation_achievement_id' => $attributes['graduation_achievement_id'] ?? null,
            'description' => $attributes['description'] ?? null,
            'duration_days' => $attributes['duration_days'] ?? 7,
            'day_mode' => $attributes['day_mode'] ?? $template->day_mode?->value ?? 'calendar',
            'allow_ahead' => $attributes['allow_ahead'] ?? $template->allow_ahead ?? false,
            'meeting_reminder_minutes' => $attributes['meeting_reminder_minutes'] ?? null,
        ]);

        // Адрес даётся один раз: сменившееся название не рвёт разосланные ссылки.
        if (! $template->exists) {
            $template->slug = $this->slugs->generate($attributes['title'], OnboardingTemplate::class);
        }

        $template->save();

        return $template;
    }
}
