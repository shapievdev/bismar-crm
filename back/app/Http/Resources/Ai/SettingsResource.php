<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Enums\AiAuthScheme;
use App\Models\AiSetting;
use App\Support\Ai\ModelSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiSetting
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effective = new ModelSettings($this->resource);

        return [
            // Что сохранено — то, что редактируется в форме.
            'model' => $this->model,
            'embedding_model' => $this->embedding_model,
            'base_url' => $this->base_url,
            'auth_scheme' => ($this->auth_scheme ?? AiAuthScheme::Bearer)->value,
            'max_tokens' => $this->max_tokens,

            // Ключ не отдаётся никогда, даже суперадминистратору: он вводится
            // один раз и после этого только заменяется целиком.
            'key_hint' => $this->keyHint(),
            'has_key' => $effective->isConfigured(),

            // Что применится на самом деле, с учётом .env. Без этого поле,
            // оставленное пустым, выглядит как «ничего не задано», хотя
            // значение приходит из переменных окружения.
            'effective' => [
                'model' => $effective->model(),
                'embedding_model' => $effective->embeddingModel(),
                'base_url' => $effective->baseUrl() ?? 'https://api.anthropic.com',
                'max_tokens' => $effective->maxTokens(),
                'auth_scheme' => $effective->authScheme()->value,
            ],

            'schemes' => AiAuthScheme::options(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
        ];
    }
}
