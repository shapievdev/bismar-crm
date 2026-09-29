<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Lms\SyncLessonTranscripts;
use App\Jobs\EmbedLesson;
use App\Jobs\EmbedRegulation;
use App\Models\Lesson;
use App\Models\MaterialVersion;
use App\Models\Regulation;

/**
 * Держит нарезку версии в согласии с её текстом — как RegulationObserver у
 * самого документа и LessonObserver у урока.
 *
 * Наблюдатель, а не вызов в контроллере: версии сохраняются из редактора, из
 * сидеров и из тестов, и любой забытый путь означал бы текст, которого
 * консультант не видит, — а значит, ответ по чужой версии тому, для кого
 * написана своя.
 */
final readonly class MaterialVersionObserver
{
    public function __construct(private SyncLessonTranscripts $sync) {}

    public function saved(MaterialVersion $version): void
    {
        // Название версии в заголовок куска не идёт — там стоит название
        // материала, — поэтому пересобирать на переименовании незачем.
        if (! $version->wasChanged('content_json') && ! $version->wasRecentlyCreated) {
            return;
        }

        $this->sync->handle($version);

        /*
         * Куски пересобраны заново, значит векторов у них нет. Считаются они по
         * материалу целиком: у версии и общего текста один корпус, и разделять
         * очередь было бы разделением ради разделения. Очередь при этом своя у
         * каждого вида — урок и документ считаются разными заданиями.
         */
        $owner = $version->owner();

        match (true) {
            $owner instanceof Lesson => EmbedLesson::dispatchIfConfigured((int) $owner->getKey()),
            $owner instanceof Regulation => EmbedRegulation::dispatchIfConfigured((int) $owner->getKey()),
            default => null,
        };
    }
}
