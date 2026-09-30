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
 *
 * **`version_id` обязан быть в этом списке.** Файл кладут либо при самом уроке,
 * либо при его версии, и версия приходит в `create()` вместе со всем остальным
 * (StoreLessonAttachment, AttachDriveFile). Без строки здесь Eloquent выбрасывал
 * её молча: запрос приходил на адрес версии, отвечал 201, а файл ложился общим —
 * и рознице был виден бланк офиса. У документов эта строка стояла с самого
 * начала, поэтому у них всё работало, и разойтись двум спискам было нечему, кроме
 * невнимательности.
 */
#[Fillable(['lesson_id', 'version_id', 'source', 'external_id', 'disk', 'path', 'name', 'description', 'mime_type', 'size'])]
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
