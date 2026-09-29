<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\MaterialVersion;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Материал, который бывает написан для разных людей по-разному.
 *
 * Версии есть у документа, справочника (2026-09-12) и урока курса
 * (2026-09-25), и связь у них одна на всех: общей версии среди строк нет — она
 * сам материал, — а порядок задаёт автор, и он же решает спор, когда человек
 * попал сразу в две.
 *
 * Кому какая версия достаётся и кто какую видит, решает не материал, а
 * App\Support\Lms\MaterialVersions.
 */
trait HasVersions
{
    /**
     * @return MorphMany<MaterialVersion, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(MaterialVersion::class, 'versionable')->ordered();
    }

    /** Разделён ли материал на версии вообще. */
    public function hasVersions(): bool
    {
        return $this->versions()->exists();
    }
}
