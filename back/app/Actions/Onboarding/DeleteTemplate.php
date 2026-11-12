<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingTemplate;

/**
 * Мягкое удаление шаблона.
 *
 * Никого не рвёт: наложенное давно живёт своей жизнью — у каждого сотрудника
 * копия этапов со своим содержимым. Удаление убирает трафарет из списка, и
 * только; родословную снятых с него этапов оно обнуляет (nullOnDelete), а
 * сами этапы остаются на своих местах.
 */
final readonly class DeleteTemplate
{
    public function handle(OnboardingTemplate $template): void
    {
        $template->delete();
    }
}
