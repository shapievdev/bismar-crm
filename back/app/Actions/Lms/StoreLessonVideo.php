<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Lesson;
use App\Models\MaterialVersion;
use Illuminate\Http\UploadedFile;

final readonly class StoreLessonVideo
{
    private const DISK = 's3';

    /**
     * Replaces the uploaded video of a lesson — or of one of its versions.
     *
     * The previous object is removed after the row is updated, so a failed
     * upload never leaves the lesson pointing at a video that no longer exists.
     *
     * Версия урока хранит запись теми же полями и по той же причине, что и сам
     * урок (2026-09-25): у розницы своё видео, у офиса своё, а проигрыватель
     * один — учить его второму виду источника незачем.
     *
     * @template T of Lesson|MaterialVersion
     *
     * @param  T  $owner
     * @return T
     */
    public function handle(Lesson|MaterialVersion $owner, UploadedFile $file): Lesson|MaterialVersion
    {
        $previous = clone $owner;

        $owner->update([
            'video_path' => $file->store($this->folderFor($owner), self::DISK),
            'video_disk' => self::DISK,
            'video_name' => $file->getClientOriginalName(),
            'video_size' => $file->getSize(),
        ]);

        $previous->deleteVideoFromStorage();

        return $owner->refresh();
    }

    /**
     * @template T of Lesson|MaterialVersion
     *
     * @param  T  $owner
     * @return T
     */
    public function remove(Lesson|MaterialVersion $owner): Lesson|MaterialVersion
    {
        $previous = clone $owner;

        $owner->update([
            'video_path' => null,
            'video_disk' => null,
            'video_name' => null,
            'video_size' => null,
        ]);

        $previous->deleteVideoFromStorage();

        return $owner->refresh();
    }

    /**
     * Где лежит запись: у версии — в папке её урока, рядом с общей.
     *
     * Своя папка на версию не нужна: имя объекта всё равно придумывает Laravel,
     * а вместе они убираются одним правилом хранилища.
     */
    private function folderFor(Lesson|MaterialVersion $owner): string
    {
        return $owner instanceof Lesson
            ? "lessons/{$owner->getKey()}/video"
            : "lessons/{$owner->versionable_id}/versions/{$owner->getKey()}/video";
    }
}
