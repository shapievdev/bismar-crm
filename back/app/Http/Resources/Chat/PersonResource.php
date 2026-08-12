<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Сотрудник в переписке: тот, кто написал, или тот, с кем говорят.
 *
 * @mixin User
 */
final class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // «Давлет К. И.» — для заголовка переписки и списка, где полное ФИО
            // не помещается и обрезается на середине фамилии.
            'short_name' => $this->shortName,

            'email' => $this->email,
            'avatar_url' => $this->avatarUrl(),

            // Дочитал ли собеседник до конца — из связующей строки, когда её
            // загрузили. Отсюда галочки о прочтении.
            'last_read_at' => $this->whenPivotLoaded(
                'conversation_participants',
                fn (): ?string => $this->pivot->last_read_at?->toIso8601String(),
            ),
        ];
    }
}
