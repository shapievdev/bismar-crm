<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Regulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Документ или справочник, приложенный к уроку, — целиком, а не ссылкой.
 *
 * Отличается от RegulationLinkResource ровно этим: сосед «рядом по теме» —
 * строка, по которой переходят, а приложенный к уроку материал читают тут же,
 * не покидая урока. Поэтому здесь едет статья и файлы, которыми она набрана:
 * без них картинки и видео внутри статьи остались бы пустыми местами.
 *
 * Статья едет только читателю урока (`sends_content`, ставит контроллер): в
 * списке приложенного у редактора она весила бы больше всего урока.
 *
 * Проверка, ознакомление и доступы сюда не идут — за ними переходят на саму
 * страницу документа, где они и работают.
 *
 * @mixin Regulation
 */
final class LessonMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,

            // Раздел и готовый адрес: к уроку прикладывают и документ, и
            // справочник, и собрать ссылку по месту нельзя — разделов два.
            'kind' => $this->kind->value,
            'path' => $this->path(),

            // Метки — редактору: у него в списке черновик стоять может, а
            // читателю такой материал не показывается вовсе.
            'is_published' => $this->isPublished(),
            'is_private' => $this->isPrivate(),

            'category' => $this->whenLoaded('category', fn (): ?string => $this->category?->name),

            'content_json' => $this->when((bool) $this->sends_content, fn () => $this->content_json),
            'attachments' => RegulationAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
