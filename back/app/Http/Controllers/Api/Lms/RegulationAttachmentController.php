<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\AttachDriveFile;
use App\Actions\Lms\StoreRegulationAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\AttachDriveFileRequest;
use App\Http\Requests\Lms\StoreAttachmentRequest;
use App\Http\Requests\Lms\UpdateAttachmentRequest;
use App\Http\Resources\Lms\RegulationAttachmentResource;
use App\Models\Regulation;
use App\Models\RegulationAttachment;
use App\Models\RegulationVersion;
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
     * Приложить файл, лежащий на Google Диске. Устроено так же, как у урока,
     * см. LessonAttachmentController::storeFromDrive.
     */
    public function storeFromDrive(
        AttachDriveFileRequest $request,
        Regulation $regulation,
        AttachDriveFile $attach,
    ): JsonResponse {
        Gate::authorize('update', $regulation);

        /** @var array{external_id: string, name: string, mime_type?: ?string, description?: ?string} $file */
        $file = $request->validated();

        /** @var RegulationAttachment $attachment */
        $attachment = $attach->handle($regulation, $file);

        return RegulationAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Файл при версии документа (2026-09-12).
     *
     * Отдельным адресом, а не полем в теле запроса: загрузка идёт
     * multipart'ом, и версия — часть того, куда кладут, а не того, что кладут.
     * Права те же, что у файла самого документа: версия — его часть.
     */
    public function storeForVersion(
        StoreAttachmentRequest $request,
        Regulation $regulation,
        RegulationVersion $version,
        StoreRegulationAttachment $storeAttachment,
    ): JsonResponse {
        Gate::authorize('update', $regulation);
        $this->ensureVersionBelongs($regulation, $version);

        $file = $request->file('file');

        abort_if($file === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

        $attachment = $storeAttachment->handle(
            $regulation,
            $file,
            $request->validated('description'),
            $version,
        );

        return RegulationAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Файл с Google Диска при версии — то же, что и при документе.
     */
    public function storeFromDriveForVersion(
        AttachDriveFileRequest $request,
        Regulation $regulation,
        RegulationVersion $version,
        AttachDriveFile $attach,
    ): JsonResponse {
        Gate::authorize('update', $regulation);
        $this->ensureVersionBelongs($regulation, $version);

        /** @var array{external_id: string, name: string, mime_type?: ?string, description?: ?string} $file */
        $file = $request->validated();

        /** @var RegulationAttachment $attachment */
        $attachment = $attach->handle($regulation, $file);

        $attachment->forceFill(['version_id' => $version->getKey()])->save();

        return RegulationAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Версия чужого документа — тот же случай, что и её отсутствие.
     */
    private function ensureVersionBelongs(Regulation $regulation, RegulationVersion $version): void
    {
        abort_if($version->regulation_id !== $regulation->getKey(), HttpResponse::HTTP_NOT_FOUND);
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
