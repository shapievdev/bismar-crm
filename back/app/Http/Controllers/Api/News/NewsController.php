<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\News;

use App\Actions\News\SaveNews;
use App\Enums\NewsAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\News\SaveNewsRequest;
use App\Http\Resources\News\NewsPersonResource;
use App\Http\Resources\News\NewsResource;
use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Новости: лента для читателя и редакция для того, кто их ведёт.
 *
 * Две разные выборки и намеренно два разных маршрута. Лента показывает только
 * опубликованное и только адресованное спрашивающему; редакция — всё подряд,
 * включая черновики. Свести их в один маршрут с флажком значило бы, что
 * забытая проверка права показывает черновик всей компании.
 */
final class NewsController extends Controller
{
    /** Сколько человек показывает подсказка поиска адресатов. */
    private const CANDIDATES = 20;

    /**
     * Лента: то, что этот человек вправе прочитать.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $reader */
        $reader = $request->user();

        $news = News::query()
            ->readableBy($reader)
            ->with('author')
            ->withExists(['acknowledgements as is_acknowledged' => fn (Builder $query) => $query
                ->where('user_id', $reader->getKey())])
            ->inFeedOrder()
            ->paginate(15)
            ->withQueryString();

        return NewsResource::collection($news);
    }

    /**
     * Сколько новостей ждут ознакомления.
     *
     * Отдельным лёгким маршрутом, потому что за числом ходит боковая рельса —
     * она есть на каждой странице, и тянуть ради значка всю ленту незачем.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        $count = News::query()
            ->readableBy($reader)
            ->where('requires_acknowledgement', true)
            ->whereDoesntHave('acknowledgements', fn (Builder $query) => $query
                ->where('user_id', $reader->getKey()))
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    /**
     * Редакция: всё, включая черновики и снятое с публикации.
     */
    public function manage(): AnonymousResourceCollection
    {
        Gate::authorize('create', News::class);

        $news = News::query()
            ->with('author')
            ->withCount('acknowledgements')
            ->inFeedOrder()
            ->paginate(15)
            ->withQueryString();

        $news->getCollection()->each($this->attachAudienceSize(...));

        return NewsResource::collection($news);
    }

    public function show(Request $request, News $news): NewsResource
    {
        Gate::authorize('view', $news);

        /** @var User $reader */
        $reader = $request->user();

        $news->load('author', 'attachments', 'quiz.questions.options');

        // Составителю едет ещё и список адресатов — ему им управлять.
        if ($reader->can('update', $news)) {
            $news->load('recipients');
            $this->attachAudienceSize($news);
            $news->loadCount('acknowledgements');
        }

        $acknowledgement = $news->acknowledgements()->where('user_id', $reader->getKey())->first();

        $news->setAttribute('sends_content', true);
        $news->setAttribute('is_acknowledged', $acknowledgement !== null);
        $news->setAttribute('acknowledged_at', $acknowledgement?->acknowledged_at?->toIso8601String());

        return NewsResource::make($news);
    }

    public function store(SaveNewsRequest $request, SaveNews $saveNews): JsonResponse
    {
        Gate::authorize('create', News::class);

        /** @var User $author */
        $author = $request->user();

        $news = $saveNews->handle($request->toAttributes(), $author);

        return NewsResource::make($this->forEditor($news))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(SaveNewsRequest $request, News $news, SaveNews $saveNews): NewsResource
    {
        Gate::authorize('update', $news);

        /** @var User $author */
        $author = $request->user();

        return NewsResource::make($this->forEditor($saveNews->handle($request->toAttributes(), $author, $news)));
    }

    public function destroy(News $news): Response
    {
        Gate::authorize('delete', $news);

        // Мягко: за новостью стоят отметки об ознакомлении, и случайное
        // удаление не должно уносить их с собой.
        $news->delete();

        return response()->noContent();
    }

    /**
     * Кого можно назвать адресатом — поиском: сотрудников тысячи, нужен один.
     */
    public function people(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('create', News::class);

        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return NewsPersonResource::collection($people);
    }

    private function forEditor(News $news): News
    {
        $news->load('author', 'recipients', 'attachments', 'quiz.questions.options');
        $news->loadCount('acknowledgements');
        $news->setAttribute('sends_content', true);

        return $this->attachAudienceSize($news);
    }

    /**
     * Скольким людям адресована новость.
     *
     * Для новости «всем» это все, кто вообще есть в системе: именно с этим
     * числом читатель сравнивает счётчик ознакомившихся.
     */
    private function attachAudienceSize(News $news): News
    {
        $size = $news->audience === NewsAudience::Everyone
            ? User::query()->count()
            : $news->recipients()->count();

        return $news->setAttribute('audience_size', $size);
    }
}
