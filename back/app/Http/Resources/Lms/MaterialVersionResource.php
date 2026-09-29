<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\MaterialVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Версия документа — как её показывают в переключателе и как читают целиком.
 *
 * Тело едет только у той версии, которую открыли: переключатель из пяти строк
 * весил бы пятью статьями, а читают за раз одну. Тот же приём, что и у самого
 * документа в каталоге, — см. `sends_content` в RegulationResource.
 *
 * @mixin MaterialVersion
 */
final class MaterialVersionResource extends JsonResource
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
            // этот список ни о чём не говорит и в переключатель не едет.
            'groups' => $this->when(
                $this->relationLoaded('groups'),
                fn (): array => $this->groups->map(static fn ($group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                ])->all(),
            ),

            // Отдел рядом с группой (2026-09-12): охватывает он и всё, что под
            // ним, — см. MaterialVersions.
            'departments' => $this->when(
                $this->relationLoaded('departments'),
                fn (): array => $this->departments->map(static fn ($department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                ])->all(),
            ),

            /* ---------- Тело: статья, файлы и проверка ---------- */

            'content_json' => $this->when((bool) $this->sends_content, fn () => $this->content_json),

            /*
             * Файлы версии. Таблица у них разная — у урока своя, у документа
             * своя, — и ресурс тоже: у вложения урока есть поля, которых у
             * документа нет. Какие показывать, решает материал версии.
             */
            'attachments' => $this->when(
                (bool) $this->sends_content,
                fn (): array => $this->resource->isOfLesson()
                    ? LessonAttachmentResource::collection($this->resource->files())->resolve()
                    : RegulationAttachmentResource::collection($this->resource->files())->resolve(),
            ),

            /*
             * Запись версии — то, чего у документа не бывает вовсе
             * (2026-09-25): у розницы своё видео, у офиса своё.
             *
             * Адрес загруженного файла — подписанный и недолгий, как у самого
             * урока; ссылка на YouTube едет как есть.
             */
            'video_url' => $this->when(
                (bool) $this->sends_content && $this->resource->isOfLesson(),
                fn (): ?string => $this->video_url,
            ),
            'video_upload_url' => $this->when(
                (bool) $this->sends_content && $this->resource->isOfLesson(),
                fn (): ?string => $this->resource->videoUrl(),
            ),
            'video_name' => $this->when(
                (bool) $this->sends_content && $this->resource->isOfLesson(),
                fn (): ?string => $this->video_name,
            ),

            // Проверка при версии: сдал её — значит ознакомился с документом.
            'quiz' => $this->when(
                (bool) $this->sends_content,
                fn () => QuizResource::make($this->whenLoaded('quiz')),
            ),

            // Опрос при версии — свой, как и проверка.
            'survey' => $this->when(
                (bool) $this->sends_content,
                fn () => SurveyResource::make($this->whenLoaded('survey')),
            ),

            // Свои прошлые попытки по этой версии. Проставляет контроллер.
            'own_attempts' => $this->when((bool) $this->sends_content, fn () => $this->own_attempts ?? []),
        ];
    }
}
