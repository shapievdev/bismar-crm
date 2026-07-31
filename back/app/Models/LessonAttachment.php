<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['lesson_id', 'disk', 'path', 'name', 'mime_type', 'size'])]
class LessonAttachment extends Model
{
    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * A short-lived signed URL, so bucket objects stay private and a leaked
     * link stops working instead of granting permanent access.
     */
    public function temporaryUrl(): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
    }

    /**
     * Removes the stored object. Called when the attachment row goes away.
     */
    public function deleteFromStorage(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
