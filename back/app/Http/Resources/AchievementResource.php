<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Достижение в ответе API — каталогом, значком при шаге и заслугой в профиле.
 *
 * Три вида одного и того же, и все три живут здесь: разойдись они по экранам,
 * одна и та же картинка приезжала бы под тремя разными ключами.
 *
 * @mixin Achievement
 */
final class AchievementResource extends JsonResource
{
    /**
     * Каталог: всё, что нужно экрану со справочником.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'position' => $this->position,

            // Спрятанное: из выбора ушло, в чужих профилях осталось.
            'is_hidden' => $this->deleted_at !== null,

            // Сколько людей его носит: прежде чем прятать «Первый выход в
            // зал», администратор вправе знать, что он у сорока человек.
            'people_count' => $this->whenCounted('awards'),

            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->getKey(),
                'name' => $this->author->name,
            ]),
        ];
    }

    /**
     * Значок при шаге маршрута: чем меньше, тем лучше — он едет в каждой
     * строке каждого этапа.
     *
     * @return ?array<string, mixed>
     */
    public static function badge(?Achievement $achievement): ?array
    {
        if ($achievement === null) {
            return null;
        }

        return [
            'id' => (int) $achievement->getKey(),
            'title' => $achievement->title,
            'image_url' => $achievement->imageUrl(),
        ];
    }

    /**
     * Заслуга в профиле: значок плюс то, когда и за что он получен.
     *
     * Читает сводную строку (`pivot`), поэтому ждёт достижение, пришедшее
     * связью User::achievements().
     *
     * @return array<string, mixed>
     */
    public static function awarded(Achievement $achievement): array
    {
        $pivot = $achievement->getRelationValue('pivot');

        return [
            'id' => (int) $achievement->getKey(),
            'title' => $achievement->title,
            'description' => $achievement->description,
            'image_url' => $achievement->imageUrl(),
            'awarded_at' => $pivot?->awarded_at,
            'reason' => $pivot?->reason,
        ];
    }
}
