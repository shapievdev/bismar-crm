<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreRegulationCategoryRequest;
use App\Http\Resources\Lms\RegulationCategoryResource;
use App\Models\RegulationCategory;
use App\Support\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Категории регламентов — своё дерево, не общее с учебными.
 */
final class RegulationCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // Деревом, а не плоским списком: вложенность рисует интерфейс, и плоский
        // список заставил бы его собирать иерархию заново.
        $categories = RegulationCategory::query()
            ->roots()
            ->with('descendants')
            ->withVisibleRegulationCounts()
            ->ordered()
            ->get();

        return RegulationCategoryResource::collection($categories);
    }

    public function store(StoreRegulationCategoryRequest $request, SlugGenerator $slugs): JsonResponse
    {
        $category = RegulationCategory::create([
            'name' => $request->validated('name'),
            'slug' => $slugs->generate((string) $request->validated('name'), RegulationCategory::class),
            'description' => $request->validated('description'),
            'parent_id' => $request->validated('parent_id'),
            'position' => $request->validated('position', RegulationCategory::query()->count()),
        ]);

        return RegulationCategoryResource::make($category)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        StoreRegulationCategoryRequest $request,
        RegulationCategory $category,
    ): RegulationCategoryResource {
        // Адрес — это то, как категория названа в интерфейсе, и он не меняется.
        $category->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'parent_id' => $request->validated('parent_id'),
            'position' => $request->validated('position', $category->position),
        ]);

        return RegulationCategoryResource::make($category);
    }

    public function destroy(RegulationCategory $category): Response
    {
        // Регламенты не уходят вместе с категорией — у них она обнуляется, — а
        // подкатегории уходят: ветка без корня недостижима.
        $category->delete();

        return response()->noContent();
    }
}
