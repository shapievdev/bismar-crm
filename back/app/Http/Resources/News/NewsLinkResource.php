<?php

declare(strict_types=1);

namespace App\Http\Resources\News;

use App\Models\NewsLink;
use App\Support\News\LinkedMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Куда сходить после новости.
 *
 * Отдаётся готовой ссылкой, а не парой «вид и номер»: у модуля своей страницы
 * нет и он ведёт на курс, у урока адрес складывается из курса и номера — и
 * собирать это заново в каждом месте, где ссылки показывают, значит однажды
 * собрать по-разному.
 *
 * @mixin NewsLink
 */
final class NewsLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $material = $this->linkable;

        return [
            'id' => $this->id,
            'kind' => $this->linkable_type,
            'kind_label' => LinkedMaterial::kindLabel((string) $this->linkable_type),
            'item_id' => $this->linkable_id,
            'title' => $material === null ? null : LinkedMaterial::title($material),
            'subtitle' => $material === null ? null : LinkedMaterial::subtitle($material),
            'url' => $material === null ? null : LinkedMaterial::url($material),
        ];
    }
}
