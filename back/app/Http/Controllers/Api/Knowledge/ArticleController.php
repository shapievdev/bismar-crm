<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Knowledge;

use App\Actions\Knowledge\SaveArticle;
use App\Enums\ArticleStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StoreArticleRequest;
use App\Http\Requests\Knowledge\UpdateArticleRequest;
use App\Http\Resources\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ArticleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $articles = KnowledgeArticle::query()
            ->with('category', 'author')
            ->matching($request->query('search'))
            ->when(
                $request->filled('category'),
                fn ($query) => $query->whereRelation('category', 'slug', $request->query('category')),
            )
            ->when(
                // Drafts stay hidden from readers who could not edit them anyway.
                $user->cannot(Permission::UpdateKnowledge->value),
                fn ($query) => $query->readable(),
                fn ($query) => $query->when(
                    $request->filled('status'),
                    fn ($query) => $query->where('status', $request->query('status')),
                ),
            )
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return KnowledgeArticleResource::collection($articles);
    }

    public function show(KnowledgeArticle $article): KnowledgeArticleResource
    {
        // A draft must not reveal its existence to a plain reader, so a failed
        // check reads as "not found" rather than "forbidden".
        if (Gate::denies('view', $article)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return KnowledgeArticleResource::make($article->load('category', 'author'));
    }

    public function store(StoreArticleRequest $request, SaveArticle $saveArticle): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        return KnowledgeArticleResource::make($saveArticle->create($request->toData(), $author))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        UpdateArticleRequest $request,
        KnowledgeArticle $article,
        SaveArticle $saveArticle,
    ): KnowledgeArticleResource {
        Gate::authorize('update', $article);

        return KnowledgeArticleResource::make($saveArticle->update($article, $request->toData()));
    }

    public function destroy(KnowledgeArticle $article): Response
    {
        Gate::authorize('delete', $article);

        // Soft delete: knowledge is worth recovering after a wrong click.
        $article->delete();

        return response()->noContent();
    }

    /**
     * The status vocabulary, so the editor never hardcodes it.
     */
    public function statuses(): JsonResponse
    {
        $statuses = array_map(
            static fn (ArticleStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            ArticleStatus::cases(),
        );

        return response()->json(['data' => $statuses]);
    }
}
