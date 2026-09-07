<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Regulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Соседний документ — строкой в блоке «рядом по теме» или в подсказке поиска.
 *
 * Ровно то, по чему соседа узнают и открывают: название и адрес. Статья, файлы
 * и проверка сюда не идут — за ними переходят по ссылке, а в блоке из пяти
 * строк они весили бы больше самого документа.
 *
 * @mixin Regulation
 */
final class RegulationLinkResource extends JsonResource
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

            // Раздел и готовый адрес: «частым вопросом» к правилу прикалывают и
            // справочник, и собирать ссылку по месту стало нельзя — разделов
            // два. Соседям по теме это не мешает: у них раздел тот же самый.
            'kind' => $this->kind->value,
            'path' => $this->path(),

            // Метки — редактору: в его списке соседей черновик и закрытый
            // документ стоять могут, а читателю они не показываются вовсе.
            'is_published' => $this->isPublished(),
            'is_private' => $this->isPrivate(),

            // Где сосед лежит: в подсказке поиска два похожих названия из
            // разных категорий иначе не различить.
            'category' => $this->whenLoaded('category', fn (): ?string => $this->category?->name),
        ];
    }
}
