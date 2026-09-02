<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\AttachDriveFile;
use App\Actions\Lms\StoreLessonAttachment;
use App\Actions\Lms\StoreLessonVideo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\AttachDriveFileRequest;
use App\Http\Requests\Lms\StoreAttachmentRequest;
use App\Http\Requests\Lms\StoreVideoRequest;
use App\Http\Requests\Lms\UpdateAttachmentRequest;
use App\Http\Resources\Lms\LessonAttachmentResource;
use App\Http\Resources\Lms\LessonResource;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class LessonAttachmentController extends Controller
{
    public function store(
        StoreAttachmentRequest $request,
        Lesson $lesson,
        StoreLessonAttachment $storeAttachment,
    ): JsonResponse {
        $attachment = $storeAttachment->handle(
            $lesson,
            $request->file('file'),
            $request->validated('description'),
        );

        return LessonAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Приложить файл, лежащий на Google Диске.
     *
     * Отдельный адрес, а не тот же `store`: там приходит сам файл и тратится
     * место в корзине, здесь — только номер чужого файла. Право то же: и то и
     * другое — правка урока.
     */
    public function storeFromDrive(
        AttachDriveFileRequest $request,
        Lesson $lesson,
        AttachDriveFile $attach,
    ): JsonResponse {
        /** @var array{external_id: string, name: string, mime_type?: ?string, description?: ?string} $file */
        $file = $request->validated();

        /** @var LessonAttachment $attachment */
        $attachment = $attach->handle($lesson, $file);

        return LessonAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        UpdateAttachmentRequest $request,
        LessonAttachment $attachment,
    ): LessonAttachmentResource {
        $attachment->update(['description' => $request->validated('description')]);

        return LessonAttachmentResource::make($attachment);
    }

    public function destroy(LessonAttachment $attachment, StoreLessonAttachment $storeAttachment): Response
    {
        $storeAttachment->delete($attachment);

        return response()->noContent();
    }

    public function storeVideo(
        StoreVideoRequest $request,
        Lesson $lesson,
        StoreLessonVideo $storeVideo,
    ): LessonResource {
        return LessonResource::make($storeVideo->handle($lesson, $request->file('video')));
    }

    public function destroyVideo(Lesson $lesson, StoreLessonVideo $storeVideo): LessonResource
    {
        return LessonResource::make($storeVideo->remove($lesson));
    }
}
