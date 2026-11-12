<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Models\Achievement;
use App\Support\Lms\StoredFiles;
use App\Support\Media\WebpImage;
use Illuminate\Http\UploadedFile;

/**
 * Картинка значка: заменить и убрать.
 *
 * Устроено как аватарка (App\Actions\User\StoreAvatar) и по той же причине:
 * имя объекту в бакете даёт Laravel, а не клиент, и вытесненный файл
 * подчищается после того, как замена уже удалась, — её неудачная уборка не
 * должна оборачиваться ошибкой на экране.
 */
final readonly class StoreAchievementImage
{
    private const DISK = 's3';

    public function handle(Achievement $achievement, UploadedFile $file): Achievement
    {
        $previous = $achievement->image_path;

        // Картинка едет в хранилище одним форматом: WebP, ужатый по
        // длинной стороне. Файл, который не картинка, проходит как есть.
        $file = WebpImage::from($file);

        $achievement->update([
            'image_path' => $file->store("achievements/{$achievement->getKey()}", self::DISK),
        ]);

        StoredFiles::discard(self::DISK, $previous);

        return $achievement->refresh();
    }

    public function remove(Achievement $achievement): Achievement
    {
        $path = $achievement->image_path;

        $achievement->update(['image_path' => null]);

        StoredFiles::discard(self::DISK, $path);

        return $achievement->refresh();
    }
}
