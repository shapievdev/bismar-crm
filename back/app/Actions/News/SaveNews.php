<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Jobs\SendPush;
use App\Models\News;
use App\Models\NewsLink;
use App\Models\User;
use App\Support\News\Addressees;
use App\Support\Push\PushMessage;
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
    public function __construct(private Addressees $addressees) {}

    /**
     * @param  array{
     *     title: string,
     *     excerpt?: ?string,
     *     content_json?: ?array<string, mixed>,
     *     status: string,
     *     is_pinned?: bool,
     *     audience: string,
     *     requires_acknowledgement?: bool,
     *     recipients?: list<int>,
     *     department_ids?: list<int>,
     *     group_ids?: list<int>,
     *     links?: list<array{type: string, id: int}>
     * } $attributes
     */
    public function handle(array $attributes, User $author, ?News $news = null): News
    {
        // Вышла ли новость к людям **сейчас**: правка уже опубликованной не
        // повод будить всю компанию во второй раз.
        $wasVisible = $news !== null && $news->exists && $news->status->isVisibleToReaders();

        $saved = DB::transaction(function () use ($attributes, $author, $news): News {
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

            $this->syncAddressees($news, $audience, $attributes);
            $this->syncLinks($news, $attributes['links'] ?? []);

            return $news->load('author', 'recipients', 'departments', 'groups', 'links.linkable');
        });

        if (! $wasVisible && $saved->status->isVisibleToReaders()) {
            $this->notify($saved, $author);
        }

        return $saved;
    }

    /**
     * Уведомление о вышедшей новости.
     *
     * Автору не шлём: он знает, что нажал «опубликовать». Адресаты — те же, кто
     * увидит новость в ленте: вся компания либо выбранные люди, отделы и группы.
     * Уволенным не шлём — платформа им закрыта, и звать их туда незачем.
     */
    private function notify(News $news, User $author): void
    {
        $people = $this->addressees->query($news)
            ->employed()
            ->pluck('id')
            ->map(intval(...))
            ->reject(fn (int $id): bool => $id === (int) $author->getKey())
            ->values()
            ->all();

        SendPush::dispatch($people, new PushMessage(
            title: $news->requires_acknowledgement ? 'Новость: нужно ознакомиться' : 'Новость компании',
            body: PushMessage::shorten($news->title),
            url: '/news/'.$news->slug,
            // Своё имя у каждой новости: две вышедшие подряд не должны
            // заменять одна другую.
            tag: 'news-'.$news->getKey(),
        ));
    }

    /**
     * Куда сходить после новости. Список задаётся целиком, как и адресаты, а
     * порядок присланного и есть порядок ссылок.
     *
     * @param  list<array{type: string, id: int}>  $links
     */
    private function syncLinks(News $news, array $links): void
    {
        $wanted = [];

        foreach ($links as $link) {
            $key = $link['type'].':'.$link['id'];

            $wanted[$key] ??= ['type' => $link['type'], 'id' => (int) $link['id']];
        }

        // Через модель, а не через связь: у связи есть сортировка, а
        // DELETE ... ORDER BY Postgres не понимает.
        NewsLink::query()->where('news_id', $news->getKey())->delete();

        foreach (array_values($wanted) as $position => $link) {
            $news->links()->create([
                'linkable_type' => $link['type'],
                'linkable_id' => $link['id'],
                'position' => $position,
            ]);
        }
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
     * Адресаты: названные поимённо, отделы и группы.
     *
     * Все три задаются целиком, как и ссылки: экран присылает то, что видно в
     * нём сейчас, а не разницу с прежним.
     *
     * @param  array{recipients?: list<int>, department_ids?: list<int>, group_ids?: list<int>}  $attributes
     */
    private function syncAddressees(News $news, NewsAudience $audience, array $attributes): void
    {
        if (! $audience->isSelected()) {
            // Новость для всех выбранных адресатов не держит: оставленные, они
            // ожили бы при обратном переключении и адресовали бы её людям,
            // которых сегодня никто не выбирал.
            $news->recipients()->detach();
            $news->departments()->detach();
            $news->groups()->detach();

            return;
        }

        $news->recipients()->sync($this->ids($attributes['recipients'] ?? []));
        $news->departments()->sync($this->ids($attributes['department_ids'] ?? []));
        $news->groups()->sync($this->ids($attributes['group_ids'] ?? []));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
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
