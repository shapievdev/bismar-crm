<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\RegulationVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Версия документа — как её показывают в переключателе и как читают целиком.
 *
 * Тело едет только у той версии, которую открыли: переключатель из пяти строк
 * весил бы пятью статьями, а читают за раз одну. Тот же приём, что и у самого
 * документа в каталоге, — см. `sends_content` в RegulationResource.
 *
 * @mixin RegulationVersion
 */
final class RegulationVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // Закрытая видна только своим группам — читателю это важно знать:
            // пересказывать её коллеге, которого в группу не внесли, не стоит.
            'is_private' => $this->is_private,
            'position' => $this->position,

            // Эта ли версия — его. Проставляет контроллер: он один знает, кто
            // спрашивает.
            'is_mine' => (bool) $this->is_mine,

            // Для кого версия написана — тому, кто документ ведёт. Читателю
            // список групп ни о чём не говорит и в переключатель не едет.
            'groups' => $this->when(
                $this->relationLoaded('groups'),
                fn (): array => $this->groups->map(static fn ($group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                ])->all(),
            ),

            /* ---------- Тело: статья, файлы и проверка ---------- */

            'content_json' => $this->when((bool) $this->sends_content, fn () => $this->content_json),

            'attachments' => $this->when(
                (bool) $this->sends_content,
                fn (): array => RegulationAttachmentResource::collection($this->attachments)->resolve(),
            ),

            // Проверка при версии: сдал её — значит ознакомился с документом.
            'quiz' => $this->when(
                (bool) $this->sends_content,
                fn () => QuizResource::make($this->whenLoaded('quiz')),
            ),

            // Свои прошлые попытки по этой версии. Проставляет контроллер.
            'own_attempts' => $this->when((bool) $this->sends_content, fn () => $this->own_attempts ?? []),
        ];
    }
}
