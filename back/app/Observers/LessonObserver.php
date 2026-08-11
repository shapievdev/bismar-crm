<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Lms\SyncLessonTranscripts;
use App\Jobs\EmbedLesson;
use App\Models\Lesson;

/**
 * Держит расшифровки блоков статьи в согласии с её текстом.
 *
 * Наблюдатель, а не вызов в контроллере: уроки сохраняются из редактора, из
 * сидеров и из тестов, и любой забытый путь означал бы урок, которого
 * консультант не видит.
 */
final readonly class LessonObserver
{
    public function __construct(private SyncLessonTranscripts $sync) {}

    public function saved(Lesson $lesson): void
    {
        // Заголовок урока хранится рядом с текстом куска и весит в поиске
        // больше тела, поэтому переименование урока — тоже повод пересобрать.
        if ($lesson->wasChanged(['content', 'content_json', 'title']) || $lesson->wasRecentlyCreated) {
            $this->sync->handle($lesson);

            // Куски пересобраны заново, значит векторов у них нет. Без этого
            // они появлялись бы только после ручного запуска команды
            // переиндексации, а до тех пор отредактированный урок падал бы в
            // конец смысловой пересортировки.
            EmbedLesson::dispatchIfConfigured((int) $lesson->getKey());
        }
    }
}
