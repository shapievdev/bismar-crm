<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Course;
use App\Support\Lms\StoredFiles;
use Illuminate\Http\UploadedFile;

final readonly class StoreCourseCover
{
    private const DISK = 's3';

    /**
     * Replaces a course cover, removing the previous object so re-uploads do
     * not accumulate orphans in the bucket.
     */
    public function handle(Course $course, UploadedFile $file): Course
    {
        $previous = $course->cover_path;

        $course->update([
            'cover_path' => $file->store("courses/{$course->getKey()}/cover", self::DISK),
        ]);

        // Замена состоялась; неудачная уборка старого файла её не отменяет.
        StoredFiles::discard(self::DISK, $previous);

        return $course->refresh();
    }

    public function remove(Course $course): Course
    {
        $path = $course->cover_path;

        $course->update(['cover_path' => null]);

        StoredFiles::discard(self::DISK, $path);

        return $course->refresh();
    }
}
