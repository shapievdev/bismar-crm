<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $user->refresh();
    }

    public function remove(User $user): User
    {
        $path = $user->avatar_path;

        $user->update(['avatar_path' => null]);

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $user->refresh();
    }
}
