<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingTemplateStatus;
use App\Exceptions\ConflictException;
use App\Models\OnboardingTemplate;
use App\Support\Onboarding\ProfessionalKit;

/**
 * Черновик, публикация, архив — отдельным решением со своими проверками.
 *
 * Публиковать пустую программу нельзя: назначенный маршрут без этапов — это
 * карта без точек. Архив идущих не рвёт — они снапшотили своё и дойдут.
 */
final readonly class ChangeTemplateStatus
{
    /**
     * @throws ConflictException
     */
    public function handle(OnboardingTemplate $template, OnboardingTemplateStatus $status): OnboardingTemplate
    {
        if ($status === OnboardingTemplateStatus::Published && ! $template->stages()->exists()) {
            throw new ConflictException('В маршруте пока нет этапов — публиковать нечего.');
        }

        if ($status === OnboardingTemplateStatus::Published) {
            $finals = $template->stages()->where('is_final_exam', true)->with('quiz.questions')->get();
            if ($finals->count() > 1) {
                throw new ConflictException('Оставьте один итоговый экзамен в программе.');
            }
            if ($template->requires_graduation && ProfessionalKit::admission($template->kit_items) !== null && ($finals->count() !== 1 || $finals->first()->quiz === null || $finals->first()->quiz->questions->isEmpty())) {
                throw new ConflictException('Для программы с допуском отметьте один итоговый экзамен и добавьте вопросы.');
            }
        }
        $template->fill(['status' => $status])->save();

        return $template;
    }
}
