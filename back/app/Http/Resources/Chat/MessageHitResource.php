<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Находка поиска — строка списка, а не сообщение.
 *
 * Нарочно скупее MessageResource, и дело не в байтах. Полный вид подписывает
 * каждое вложение ссылкой в хранилище, а это обращение к S3 на файл: сорок
 * находок превратились бы в сорок обращений ради списка, в котором файлы всё
 * равно не показывают. Нажав на находку, человек попадает в саму ленту, и там
 * реплика приезжает целиком.
 *
 * Названия переписки здесь тоже нет: список переписок у вкладки уже на руках, и
 * она подписывает находку сама — по номеру разговора.
 *
 * @mixin Message
 */
final class MessageHitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'author' => PersonResource::make($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
