<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Regulation;

/**
 * Что убрать из хранилища вместе с курсом или документом.
 *
 * Строки в базе уносит каскад: удалили курс — ушли модули, уроки, вложения,
 * тесты и попытки. Файлы в корзине S3 каскад не трогает: он про внешние ключи,
 * а не про чужое хранилище. Осиротевшие объекты — не беда (их подметёт правило
 * жизненного цикла бакета), но и оставлять их пачками, когда мы точно знаем
 * адреса, незачем: место платное, а стереть их больше будет некому.
 *
 * Собирается адресами до удаления записей — после них спрашивать уже некого.
 */
final readonly class DiscardedFiles
{
    /**
     * @return list<array{disk: string, path: string}>
     */
    public function of(Course|Regulation $material): array
    {
        return $material instanceof Course
            ? $this->ofCourse($material)
            : $this->ofRegulation($material);
    }

    /**
     * Убирает собранное. Неудача не отменяет удаления — см. StoredFiles.
     *
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function discard(array $files): void
    {
        foreach ($files as $file) {
            StoredFiles::discard($file['disk'], $file['path']);
        }
    }

    /**
     * @return list<array{disk: string, path: string}>
     */
    private function ofCourse(Course $course): array
    {
        $files = [];

        // Обложка лежит в той же корзине, что и всё прочее (см. Course::coverUrl).
        if ($course->cover_path !== null) {
            $files[] = ['disk' => 's3', 'path' => (string) $course->cover_path];
        }

        $course->loadMissing('modules.lessons.attachments');

        foreach ($course->modules as $module) {
            foreach ($module->lessons as $lesson) {
                if ($lesson->video_path !== null && $lesson->video_disk !== null) {
                    $files[] = ['disk' => (string) $lesson->video_disk, 'path' => (string) $lesson->video_path];
                }

                foreach ($lesson->attachments as $attachment) {
                    // Файл с Google Диска не наш: отвязать его от урока — не то
                    // же самое, что стереть у автора на Диске.
                    if ($attachment->isFromDrive() || $attachment->path === null) {
                        continue;
                    }

                    $files[] = ['disk' => (string) $attachment->disk, 'path' => (string) $attachment->path];
                }
            }
        }

        return $files;
    }

    /**
     * @return list<array{disk: string, path: string}>
     */
    private function ofRegulation(Regulation $regulation): array
    {
        $files = [];

        foreach ($regulation->loadMissing('attachments')->attachments as $attachment) {
            if ($attachment->isFromDrive() || $attachment->path === null) {
                continue;
            }

            $files[] = ['disk' => (string) $attachment->disk, 'path' => (string) $attachment->path];
        }

        return $files;
    }
}
