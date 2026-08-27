<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Заводит новость и правит её.
 *
 * Одно действие на оба случая: разница между «создать» и «сохранить» здесь
 * только в том, есть ли уже строка, — правила о дате публикации и об адресатах
 * одни и те же, и разводить их по двум классам значило бы однажды поправить
 * одно из них.
 */
final readonly class SaveNews
{
    /**
     * @param  array{
     *     title: string,
     *     excerpt?: ?string,
     *     content_json?: ?array<string, mixed>,
     *     status: string,
     *     is_pinned?: bool,
     *     audience: string,
     *     requires_acknowledgement?: bool,
     *     recipients?: list<int>
     * } $attributes
     */
    public function handle(array $attributes, User $author, ?News $news = null): News
    {
        return DB::transaction(function () use ($attributes, $author, $news): News {
            $status = NewsStatus::from($attributes['status']);
            $audience = NewsAudience::from($attributes['audience']);

            $news ??= new News(['author_id' => $author->getKey()]);

            $news->fill([
                'title' => $attributes['title'],
                'excerpt' => $attributes['excerpt'] ?? null,
                'content_json' => $attributes['content_json'] ?? null,
                'status' => $status,
                'is_pinned' => $attributes['is_pinned'] ?? false,
                'audience' => $audience,
                'requires_acknowledgement' => $attributes['requires_acknowledgement'] ?? false,
                'published_at' => $this->publishedAt($news, $status),
            ]);

            // Адрес новости не меняется вслед за заголовком: ссылку на неё уже
            // могли отправить в мессенджере, и опечатка в названии не повод её
            // сломать. Заводится он один раз, при создании.
            $news->slug ??= $this->uniqueSlug($attributes['title']);

            $news->save();

            $this->syncRecipients($news, $audience, $attributes['recipients'] ?? []);

            return $news->load('author', 'recipients');
        });
    }

    /**
     * Дата публикации ставится в тот момент, когда новость впервые вышла, и
     * дальше не двигается: правка опечатки не должна поднимать новость на верх
     * ленты. Снятая с публикации и выпущенная заново — новость того же дня.
     */
    private function publishedAt(News $news, NewsStatus $status): ?string
    {
        if (! $status->isVisibleToReaders()) {
            return $news->published_at?->toDateTimeString();
        }

        return ($news->published_at ?? now())->toDateTimeString();
    }

    /**
     * @param  list<int>  $recipients
     */
    private function syncRecipients(News $news, NewsAudience $audience, array $recipients): void
    {
        if (! $audience->isSelected()) {
            // Новость для всех поимённого списка не держит: оставленный, он
            // ожил бы при обратном переключении и адресовал бы её людям,
            // которых сегодня никто не выбирал.
            $news->recipients()->detach();

            return;
        }

        $news->recipients()->sync(array_values(array_unique(array_map(intval(...), $recipients))));
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);

        // Заголовок целиком из кириллицы транслитерируется, но заголовок из
        // одних знаков препинания не даёт ничего — тогда имя выдаётся случайное.
        $base = $base === '' ? Str::lower(Str::random(8)) : $base;

        $slug = $base;
        $suffix = 2;

        while (News::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
