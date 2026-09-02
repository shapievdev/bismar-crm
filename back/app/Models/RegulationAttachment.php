<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AttachedFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Файл при документе: приложенный бланк или картинка и видео из статьи.
 *
 * Устроен как файл при уроке — и тем же способом бывает не нашим, а лежащим на
 * Google Диске: рассуждение об адресах у них одно, см. AttachedFile.
 */
#[Fillable(['regulation_id', 'source', 'external_id', 'disk', 'path', 'name', 'description', 'mime_type', 'size'])]
class RegulationAttachment extends Model
{
    use AttachedFile;

    /**
     * @return BelongsTo<Regulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(Regulation::class);
    }
}
