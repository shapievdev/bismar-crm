<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveLessonTranscript;
use App\Actions\Lms\SyncLessonTranscripts;
use App\Enums\AnswerSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveTranscriptRequest;
use App\Http\Resources\Lms\LessonTranscriptResource;
use App\Models\Lesson;
use App\Models\LessonTranscript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Расшифровки — то, чем содержание урока становится доступно консультанту.
 *
 * Весь контроллер под правом на правку курса: читателю расшифровки не видны и
 * не нужны. Он читает материал, а не его изложение для машины.
 */
final class LessonTranscriptController extends Controller
{
    public function index(Lesson $lesson): AnonymousResourceCollection
    {
        return LessonTranscriptResource::collection(
            $lesson->transcripts()->with('segments')->get(),
        );
    }

    public function store(
        SaveTranscriptRequest $request,
        Lesson $lesson,
        SaveLessonTranscript $save,
    ): JsonResponse {
        $blockId = (string) $request->validated('source_block_id', '');

        $transcript = $save->handle(
            lesson: $lesson,
            kind: AnswerSource::from((string) $request->validated('source_kind')),
            raw: $request->transcript(),
            attachmentId: $request->validated('source_attachment_id'),
            blockId: $blockId === '' ? null : $blockId,
            originalName: $request->originalName(),
        );

        return LessonTranscriptResource::make($transcript)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function destroy(
        LessonTranscript $transcript,
        SaveLessonTranscript $save,
        SyncLessonTranscripts $sync,
    ): Response {
        $save->remove($transcript->load('lesson'), $sync);

        return response()->noContent();
    }
}
