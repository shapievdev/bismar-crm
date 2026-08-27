<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\News;

use App\Actions\News\StoreNewsAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreAttachmentRequest;
use App\Http\Requests\Lms\UpdateAttachmentRequest;
use App\Http\Resources\News\NewsAttachmentResource;
use App\Models\News;
use App\Models\NewsAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Файлы при новости: документы и то, что автор вставил прямо в статью.
 *
 * Проверка загрузки взята у базы знаний как есть (StoreAttachmentRequest): её
 * правила — о размерах и типах файлов, а не об уроках, и второй экземпляр того
 * же списка расширений однажды разошёлся бы с первым.
 */
final class NewsAttachmentController extends Controller
{
    public function store(
        StoreAttachmentRequest $request,
        News $news,
        StoreNewsAttachment $storeAttachment,
    ): JsonResponse {
        Gate::authorize('update', $news);

        $file = $request->file('file');

        abort_if($file === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

        $attachment = $storeAttachment->handle($news, $file, $request->validated('description'));

        return NewsAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Правится только подпись: заменить сам файл — значит загрузить новый, и
     * тогда строка и объект в хранилище не разъезжаются.
     */
    public function update(
        UpdateAttachmentRequest $request,
        News $news,
        NewsAttachment $attachment,
    ): NewsAttachmentResource {
        Gate::authorize('update', $news);
        $this->ensureBelongs($news, $attachment);

        $attachment->update(['description' => $request->validated('description')]);

        return NewsAttachmentResource::make($attachment->refresh());
    }

    public function destroy(News $news, NewsAttachment $attachment): Response
    {
        Gate::authorize('update', $news);
        $this->ensureBelongs($news, $attachment);

        $attachment->delete();
        $attachment->deleteFromStorage();

        return response()->noContent();
    }

    /**
     * 404, а не 403: чужое вложение — не то, о существовании чего стоит
     * сообщать тому, кто спрашивает не о своей новости.
     */
    private function ensureBelongs(News $news, NewsAttachment $attachment): void
    {
        abort_if($attachment->news_id !== $news->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
