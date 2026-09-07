<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Support\Lms\Keywords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Регламент целиком — и когда его заводят, и когда правят.
 *
 * Один класс на оба случая: адрес с клиента не приходит вовсе (его выдаёт
 * SaveRegulation и больше не меняет), а всё остальное проверяется одинаково.
 */
final class SaveRegulationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],

            // Строка для каталога. Не обязательна: у короткого правила и
            // названия довольно.
            'summary' => ['nullable', 'string', 'max:500'],

            // Документ редактора блоков — тот же формат, что у урока.
            'content_json' => ['nullable', 'array'],

            'status' => ['required', Rule::enum(CourseStatus::class)],
            'visibility' => ['required', Rule::enum(CourseVisibility::class)],

            // Из дерева своего раздела: категории у документов и справочников
            // разные, и чужая означала бы материал, невидимый в каталоге.
            'category_id' => ['nullable', 'integer', Rule::exists('regulation_categories', 'id')
                ->where('kind', MaterialKind::of($this)->value)],

            // Слова, которыми документ ищут. Пустой список присылать можно:
            // это «слов больше нет».
            'keywords' => ['sometimes', 'array', 'max:'.Keywords::LIMIT],
            'keywords.*' => ['string', 'max:'.Keywords::MAX_LENGTH],
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     summary: ?string,
     *     content_json: ?array<string, mixed>,
     *     status: string,
     *     visibility: string,
     *     category_id: ?int,
     *     keywords: ?list<string>
     * }
     */
    public function toAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'title' => (string) $validated['title'],
            'summary' => $validated['summary'] ?? null,
            'content_json' => $validated['content_json'] ?? null,
            'status' => (string) $validated['status'],
            'visibility' => (string) $validated['visibility'],
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,

            // null здесь — «поля не было в запросе», а не «слов нет»: см.
            // SaveRegulation, там на этом и держится разница.
            'keywords' => array_key_exists('keywords', $validated)
                ? Keywords::clean((array) $validated['keywords'])
                : null,
        ];
    }
}
