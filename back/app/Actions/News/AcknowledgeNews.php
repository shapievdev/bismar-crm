<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Enums\NewsAcknowledgementSource;
use App\Models\News;
use App\Models\NewsAcknowledgement;
use App\Models\User;

/**
 * «Прочитал и понял».
 *
 * Ставится один раз и не снимается. Повторный вызов — не ошибка и не вторая
 * строка: человек мог нажать дважды, а два браузера — одновременно, и уникальный
 * ключ в таблице разрешает этот спор за нас.
 */
final readonly class AcknowledgeNews
{
    public function handle(
        News $news,
        User $reader,
        NewsAcknowledgementSource $source = NewsAcknowledgementSource::Confirmed,
    ): NewsAcknowledgement {
        return NewsAcknowledgement::firstOrCreate(
            ['news_id' => $news->getKey(), 'user_id' => $reader->getKey()],
            ['source' => $source, 'acknowledged_at' => now()],
        );
    }
}
