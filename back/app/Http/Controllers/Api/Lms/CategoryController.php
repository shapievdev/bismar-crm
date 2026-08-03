<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreCategoryRequest;
use App\Http\Resources\Lms\CategoryResource;
use App\Models\Category;
use App\Support\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->withCount('courses')
            ->ordered()
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request, SlugGenerator $slugs): JsonResponse
    {
        $category = Category::create([
            'name' => $request->validated('name'),
            'slug' => $slugs->generate((string) $request->validated('name'), Category::class),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', Category::query()->count()),
        ]);

        return CategoryResource::make($category)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(StoreCategoryRequest $request, Category $category): CategoryResource
    {
        // The slug is the category's address in the UI, so it is left alone.
        $category->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', $category->position),
        ]);

        return CategoryResource::make($category);
    }

    public function destroy(Category $category): Response
    {
        // Material survives: the foreign key nulls out rather than cascading.
        $category->delete();

        return response()->noContent();
    }
}
