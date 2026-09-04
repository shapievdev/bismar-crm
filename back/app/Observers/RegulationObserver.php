<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Lms\SyncLessonTranscripts;
use App\Jobs\EmbedRegulation;
use App\Models\Regulation;

/**
 * Держит нарезку документа в согласии с его текстом — как LessonObserver у
 * урока.
 *
 * Наблюдатель, а не вызов в контроллере: документы сохраняются из редактора, из
 * сидеров и из тестов, и любой забытый путь означал бы правило, которого
 * консультант не видит.
 */
final readonly class RegulationObserver
{
    public function __construct(private SyncLessonTranscripts $sync) {}

    public function saved(Regulation $regulation): void
    {
        // Название документа хранится рядом с текстом куска и весит в поиске
        // больше тела, поэтому переименование — тоже повод пересобрать.
        if ($regulation->wasChanged(['content_json', 'title']) || $regulation->wasRecentlyCreated) {
            $this->sync->handle($regulation);

            // Куски пересобраны заново, значит векторов у них нет: без этого
            // правленый документ падал бы в конец смысловой пересортировки до
            // ближайшей ручной переиндексации.
            EmbedRegulation::dispatchIfConfigured((int) $regulation->getKey());
        }
    }
}
