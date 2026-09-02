<?php

declare(strict_types=1);

namespace App\Http\Resources\Integrations;

use App\Models\GoogleSetting;
use App\Support\Lms\GoogleSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoogleSetting
 */
final class GoogleSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effective = new GoogleSettings($this->resource);

        return [
            // Что сохранено — то, что стоит в полях формы. В отличие от ключа
            // консультанта, эти значения возвращаются как есть: они всё равно
            // уезжают в браузер, чтобы открылось окно Google, и прятать их от
            // формы значило бы притворяться.
            'client_id' => $this->client_id,
            'api_key' => $this->api_key,

            // Что применится на самом деле, с учётом .env: пустое поле иначе
            // выглядит как «ничего не настроено», хотя ключи могут стоять в
            // переменных окружения.
            'effective' => [
                'client_id' => $effective->clientId(),
                'api_key' => $effective->apiKey(),
            ],

            // По этому экран решает, показывать ли кнопку «С Google Диска».
            'is_configured' => $effective->isConfigured(),

            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
        ];
    }
}
