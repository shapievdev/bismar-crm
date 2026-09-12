<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Lms\SyncLessonTranscripts;
use App\Jobs\EmbedRegulation;
use App\Models\RegulationVersion;

/**
 * Держит нарезку версии в согласии с её текстом — как RegulationObserver у
 * самого документа.
 *
 * Наблюдатель, а не вызов в контроллере: версии сохраняются из редактора, из
 * сидеров и из тестов, и любой забытый путь означал бы текст, которого
 * консультант не видит, — а значит, ответ по чужой версии тому, для кого
 * написана своя.
 */
final readonly class RegulationVersionObserver
{
    public function __construct(private SyncLessonTranscripts $sync) {}

    public function saved(RegulationVersion $version): void
    {
        // Название версии в заголовок куска не идёт — там стоит название
        // документа, — поэтому пересобирать на переименовании незачем.
        if ($version->wasChanged('content_json') || $version->wasRecentlyCreated) {
            $this->sync->handle($version);

            // Куски пересобраны заново, значит векторов у них нет. Считаются
            // они по документу целиком: у версии и общего текста один корпус,
            // и разделять очередь было бы разделением ради разделения.
            EmbedRegulation::dispatchIfConfigured((int) $version->regulation_id);
        }
    }
}
