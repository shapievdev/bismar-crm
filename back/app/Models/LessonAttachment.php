<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AttachedFile;
use App\Models\Contracts\PartOfCourse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Файл при уроке: приложенный документ или картинка и видео из статьи.
 *
 * Лежит либо в нашей корзине, либо на Google Диске автора — что именно,
 * говорит `source`, а разницу в адресах держит AttachedFile.
 */
#[Fillable(['lesson_id', 'source', 'external_id', 'disk', 'path', 'name', 'description', 'mime_type', 'size'])]
class LessonAttachment extends Model implements PartOfCourse
{
    use AttachedFile;

    public function owningCourse(): ?Course
    {
        return $this->loadMissing('lesson.module.course')->lesson?->module?->course;
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
