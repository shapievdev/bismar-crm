<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Support\Lms\StoredFiles;
use Illuminate\Http\UploadedFile;

final readonly class StoreAvatar
{
    private const DISK = 's3';

    /**
     * Replaces a user's avatar, removing the object it supersedes so repeated
     * uploads do not accumulate in the bucket.
     */
    public function handle(User $user, UploadedFile $file): User
    {
        $previous = $user->avatar_path;

        // Laravel names the stored object, so a hostile client cannot choose
        // the key or overwrite somebody else's file.
        $user->update(['avatar_path' => $file->store("avatars/{$user->getKey()}", self::DISK)]);

        // Уборка старого файла — уже не часть замены: она удалась. Неудача
        // здесь не должна отменять её для сотрудника, см. StoredFiles.
        StoredFiles::discard(self::DISK, $previous);

        return $user->refresh();
    }

    public function remove(User $user): User
    {
        $path = $user->avatar_path;

        $user->update(['avatar_path' => null]);

        StoredFiles::discard(self::DISK, $path);

        return $user->refresh();
    }
}
