<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\MaterialVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final readonly class StoreLessonAttachment
{
    /**
     * Attachments go to S3. The disk is recorded on the row so that changing
     * the default later cannot orphan files already uploaded.
     */
    private const DISK = 's3';

    /**
     * @param  MaterialVersion|null  $version  версия урока, если файл её: у
     *                                         каждой версии свои приложения,
     *                                         и общий список показал бы рознице
     *                                         бланк офиса (2026-09-25)
     */
    public function handle(
        Lesson $lesson,
        UploadedFile $file,
        ?string $description = null,
        ?MaterialVersion $version = null,
    ): LessonAttachment {
        // Laravel generates the stored filename, so a hostile client cannot
        // choose the object key; the original name is kept only for display.
        $path = $file->store("lessons/{$lesson->getKey()}", self::DISK);

        return DB::transaction(fn (): LessonAttachment => LessonAttachment::create([
            'lesson_id' => $lesson->getKey(),
            'version_id' => $version?->getKey(),
            'disk' => self::DISK,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'description' => $description,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]));
    }

    /**
     * Removes the row and the stored object together. The row goes first: an
     * attachment pointing at a missing object is worse than an orphaned object,
     * which a lifecycle rule can sweep up later.
     */
    public function delete(LessonAttachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $attachment->delete();
        });

        $attachment->deleteFromStorage();
    }
}
