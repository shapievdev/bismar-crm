<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\StaffTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Справочник ручных тегов.
 *
 * Таблицей, а не перечислением в коде: завести «Кадровый резерв» через выкат
 * значит не завести его никогда. Правит справочник кадровик, и потому у тегов
 * своё право — назначать доступ к людям и заводить ярлыки для них это разные
 * работы, и делают их разные люди.
 *
 * Тегов по стажу здесь нет и быть не может. «Стажёр», «новичок», «старожил»
 * считаются из даты приёма на лету (App\Enums\TenureTag); попади они в эту
 * таблицу — кадровик однажды снял бы «старожила» руками, а назавтра тот
 * вернулся бы сам.
 */
final class StaffTagController extends Controller
{
    /**
     * Весь справочник.
     *
     * Открыт всякому, кто видит людей: тег — часть карточки сотрудника, и без
     * названий список превратился бы в набор номеров.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->present(StaffTag::query()->ordered()->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $tag = StaffTag::query()->create($this->validated($request));

        return response()->json(['data' => $this->one($tag)], 201);
    }

    public function update(Request $request, StaffTag $tag): JsonResponse
    {
        $tag->update($this->validated($request, $tag));

        return response()->json(['data' => $this->one($tag)]);
    }

    /**
     * Удаление тега снимает его со всех, на ком он висел.
     *
     * Так и задумано: тег — это ярлык, а не событие. Удалённый «кадровый
     * резерв» не должен оставаться висеть на людях невидимой пометкой, до
     * которой не добраться ни списком, ни фильтром.
     */
    public function destroy(StaffTag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?StaffTag $tag = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('staff_tags', 'name')->ignore($tag),
            ],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    /**
     * @param  iterable<StaffTag>  $tags
     * @return list<array<string, mixed>>
     */
    private function present(iterable $tags): array
    {
        $result = [];

        foreach ($tags as $tag) {
            $result[] = $this->one($tag);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function one(StaffTag $tag): array
    {
        return [
            'id' => $tag->getKey(),
            'name' => $tag->name,
            'position' => $tag->position,
            // Сколько людей носит тег: перед тем как удалить «испытательный
            // продлён», кадровик вправе знать, что он висит на семнадцати.
            'people' => $tag->people()->count(),
        ];
    }
}
