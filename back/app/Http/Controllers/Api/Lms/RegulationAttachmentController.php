<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\StoreRegulationAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreAttachmentRequest;
use App\Http\Requests\Lms\UpdateAttachmentRequest;
use App\Http\Resources\Lms\RegulationAttachmentResource;
use App\Models\Regulation;
use App\Models\RegulationAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Файлы при регламенте: документы и то, что автор вставил прямо в статью.
 */
final class RegulationAttachmentController extends Controller
{
    public function store(
        StoreAttachmentRequest $request,
        Regulation $regulation,
        StoreRegulationAttachment $storeAttachment,
    ): JsonResponse {
        Gate::authorize('update', $regulation);

        $file = $request->file('file');

        abort_if($file === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

        $attachment = $storeAttachment->handle($regulation, $file, $request->validated('description'));

        return RegulationAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Правится только подпись: заменить сам файл — значит загрузить новый, и
     * тогда строка и объект в хранилище не разъезжаются.
     */
    public function update(
        UpdateAttachmentRequest $request,
        Regulation $regulation,
        RegulationAttachment $attachment,
    ): RegulationAttachmentResource {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $attachment);

        $attachment->update(['description' => $request->validated('description')]);

        return RegulationAttachmentResource::make($attachment->refresh());
    }

    public function destroy(Regulation $regulation, RegulationAttachment $attachment): Response
    {
        Gate::authorize('update', $regulation);
        $this->ensureBelongs($regulation, $attachment);

        $attachment->delete();
        $attachment->deleteFromStorage();

        return response()->noContent();
    }

    /**
     * 404, а не 403: чужое вложение — не то, о существовании чего стоит
     * сообщать тому, кто спрашивает не о своём регламенте.
     */
    private function ensureBelongs(Regulation $regulation, RegulationAttachment $attachment): void
    {
        abort_if($attachment->regulation_id !== $regulation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
