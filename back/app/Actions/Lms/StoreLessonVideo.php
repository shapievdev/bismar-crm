<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Lesson;
use Illuminate\Http\UploadedFile;

final readonly class StoreLessonVideo
{
    private const DISK = 's3';

    /**
     * Replaces a lesson's uploaded video.
     *
     * The previous object is removed after the row is updated, so a failed
     * upload never leaves the lesson pointing at a video that no longer exists.
     */
    public function handle(Lesson $lesson, UploadedFile $file): Lesson
    {
        $previous = clone $lesson;

        $lesson->update([
            'video_path' => $file->store("lessons/{$lesson->getKey()}/video", self::DISK),
            'video_disk' => self::DISK,
            'video_name' => $file->getClientOriginalName(),
            'video_size' => $file->getSize(),
        ]);

        $previous->deleteVideoFromStorage();

        return $lesson->refresh();
    }

    public function remove(Lesson $lesson): Lesson
    {
        $previous = clone $lesson;

        $lesson->update([
            'video_path' => null,
            'video_disk' => null,
            'video_name' => null,
            'video_size' => null,
        ]);

        $previous->deleteVideoFromStorage();

        return $lesson->refresh();
    }
}
