<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StoreCategoryRequest;
use App\Http\Resources\KnowledgeCategoryResource;
use App\Models\KnowledgeCategory;
use App\Support\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = KnowledgeCategory::query()
            ->withCount('articles')
            ->ordered()
            ->get();

        return KnowledgeCategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request, SlugGenerator $slugGenerator): JsonResponse
    {
        $category = KnowledgeCategory::create([
            'name' => $request->validated('name'),
            'slug' => $slugGenerator->generate((string) $request->validated('name'), KnowledgeCategory::class),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', 0),
        ]);

        return KnowledgeCategoryResource::make($category)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(StoreCategoryRequest $request, KnowledgeCategory $category): KnowledgeCategoryResource
    {
        // The slug is left alone: it is the category's address in the UI.
        $category->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', $category->position),
        ]);

        return KnowledgeCategoryResource::make($category);
    }

    public function destroy(KnowledgeCategory $category): Response
    {
        // Articles survive: the foreign key nulls out rather than cascading.
        $category->delete();

        return response()->noContent();
    }
}
