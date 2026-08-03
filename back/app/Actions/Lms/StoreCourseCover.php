<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Course;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $course->refresh();
    }

    public function remove(Course $course): Course
    {
        $path = $course->cover_path;

        $course->update(['cover_path' => null]);

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $course->refresh();
    }
}
