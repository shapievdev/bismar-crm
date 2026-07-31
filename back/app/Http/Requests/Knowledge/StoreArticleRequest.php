<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use App\Data\Knowledge\ArticleData;
use App\Enums\ArticleStatus;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreArticleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('knowledge_categories', 'id')],
            'status' => ['required', Rule::enum(ArticleStatus::class)],
        ];
    }

    /**
     * Publishing is a separate privilege from writing: an author without it may
     * save drafts but not put them in front of readers.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $wantsToPublish = $this->input('status') === ArticleStatus::Published->value;

            if ($wantsToPublish && $this->user()?->cannot(Permission::PublishKnowledge->value)) {
                $validator->errors()->add('status', 'У вас нет права публиковать статьи.');
            }
        });
    }

    public function toData(): ArticleData
    {
        /** @var array{title: string, content: string, status: string, excerpt?: string|null, category_id?: int|null} $validated */
        $validated = $this->validated();

        return ArticleData::fromArray($validated);
    }
}
