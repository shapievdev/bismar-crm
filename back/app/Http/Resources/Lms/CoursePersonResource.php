<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Человек на экране доступа к курсу — в списке или в подсказке поиска.
 *
 * Ровно то, чем один сотрудник отличается от другого на глаз: имя, почта,
 * лицо. Права и должность сюда не идут — их видит тот, кто ведёт людей, а
 * доступ к курсу ведёт автор, и знать о коллегах больше ему незачем.
 *
 * @mixin User
 */
final class CoursePersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl(),

            // Когда доступ открыли. Есть только у тех, кто пришёл из списка
            // курса, — у подсказки поиска связи с курсом ещё нет.
            'granted_at' => $this->whenPivotLoaded(
                'course_members',
                fn (): ?string => $this->pivot->created_at?->toIso8601String(),
            ),

            // То же самое со стороны ответственных за курс.
            'appointed_at' => $this->whenPivotLoaded(
                'course_experts',
                fn (): ?string => $this->pivot->created_at?->toIso8601String(),
            ),
        ];
    }
}
