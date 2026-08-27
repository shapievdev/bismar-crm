<?php

declare(strict_types=1);

namespace App\Http\Resources\News;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Человек на экране новости: в списке адресатов или в списке ознакомившихся.
 *
 * Ровно то, чем один сотрудник отличается от другого на глаз. Отметка об
 * ознакомлении приходит рядом, когда она есть, — её проставляет контроллер.
 *
 * @mixin User
 */
final class NewsPersonResource extends JsonResource
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

            'acknowledged_at' => $this->when(
                $this->acknowledged_at !== null,
                fn (): string => (string) $this->acknowledged_at,
            ),
            'acknowledged_via' => $this->when(
                $this->acknowledged_via !== null,
                fn (): string => (string) $this->acknowledged_via,
            ),
        ];
    }
}
